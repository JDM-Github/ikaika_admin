<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\ProjectEstimator\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The scheduled missing-report reminder that replaced the WordPress cron script (2026-10-19): it reads
 * the portal database instead of Airtable and sends through the portal outbox, so it lands in the
 * outbox and the bell like any Missing Reports reminder.
 *
 * No request and no signed-in sender stand behind a scheduled run, so the day is the office's
 * (Asia/Manila) and the sender is a guest. The window is the last few work days up to yesterday:
 * today is still fileable as a daily report, and an older day is not worth a nag.
 *
 * False reminders (2026-10-22, user: people were told they had not filed days they had). A reminder is only as
 * true as the database it reads, so two guards stand before one is sent. Only production's scheduled run sends
 * (scheduled()): every other server runs on a copy of production's reports -- the Test Server above all, with the
 * same mail relay -- and would remind people of days they have filed there since. And a day on which fewer than
 * half of those it counts for filed anything is one the records lack -- a holiday the calendar does not have, or
 * reports this database never received, as an import from Airtable leaves -- so nobody is reminded of it, and the
 * command and the log say so.
 */
final class PortalReportReminders
{
    public const ZONE = 'Asia/Manila';

    public const WORK_DAYS = 5;

    // The share of those a day counts for who must have filed before anyone is reminded of it.
    public const MIN_FILED_SHARE = 0.5;

    public function __construct(
        private readonly PortalSubmittedReports $reports,
        private readonly PortalEmailMessages $messages,
    ) {}

    /**
     * Whether the scheduled run sends here: as REPORT_REMINDERS_SCHEDULED says, else only on production, whose
     * reports are the ones people file.
     */
    public static function scheduled(): bool
    {
        $set = config('products.report_reminders.scheduled');
        if (is_bool($set)) {
            return $set;
        }

        return strtolower(trim((string) config('products.channel'))) === 'production';
    }

    /**
     * Who is missing which work days in the window ending yesterday, never a day before they started,
     * after they left, or one their leave or an offset already covers -- nor one too few filed on.
     *
     * @return list<array{employee: Employee, name: string, email: string, department: string, days: list<string>}>
     */
    public function missing(Carbon $today): array
    {
        $survey = $this->survey($today);
        $unrecorded = array_keys(array_filter($survey['coverage'], static fn (array $day): bool => ! $day['whole']));
        if ($unrecorded !== []) {
            Log::warning('portal.report-reminders.unrecorded', ['days' => $unrecorded, 'coverage' => $survey['coverage']]);
        }
        $plan = [];
        foreach ($survey['people'] as $entry) {
            $days = array_values(array_diff($entry['missing'], $unrecorded));
            if ($days !== []) {
                $plan[] = [...$entry['row'], 'days' => array_reverse($days)];
            }
        }

        return $plan;
    }

    /**
     * Each day of the window, newest first: how many it counts for, how many of them filed, and whether that is
     * enough for the records to be whole.
     *
     * @return array<string, array{expected: int, filed: int, whole: bool}>
     */
    public function coverage(Carbon $today): array
    {
        return $this->survey($today)['coverage'];
    }

    /**
     * @return array{coverage: array<string, array{expected: int, filed: int, whole: bool}>, people: list<array{row: array{employee: Employee, name: string, email: string, department: string}, missing: list<string>}>}
     */
    private function survey(Carbon $today): array
    {
        $window = $this->workDays($today->copy()->setTimezone(self::ZONE)->startOfDay());
        if ($window === []) {
            return ['coverage' => [], 'people' => []];
        }
        $from = $window[array_key_last($window)];
        $to = $window[0];

        $filed = [];
        $rows = DB::connection('portal')->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->whereBetween('user_reports.report_date', [$from, $to])
            ->select(['employees_user_reports.employee_id', 'user_reports.report_date'])
            ->get();
        foreach ($rows as $row) {
            $filed[(int) $row->employee_id][substr((string) $row->report_date, 0, 10)] = true;
        }

        $coverage = array_fill_keys($window, ['expected' => 0, 'filed' => 0, 'whole' => true]);
        $entries = [];
        $people = Employee::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'department', 'role', 'role_level', 'start_date', 'end_date'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
        foreach ($people as $person) {
            $email = trim((string) ($person->email ?? ''));
            if ($email === '' || PortalRole::isExecutive($person->role, $person->role_level)) {
                continue;
            }
            $start = $this->date($person->start_date);
            $end = $this->date($person->end_date);
            $away = array_flip([...$this->reports->leaveDays($person, $from, $to), ...$this->reports->offsetDays($person, $from, $to)]);
            $counted = array_values(array_filter($window, static fn (string $day): bool => ! isset($away[$day])
                && ($start === null || $day >= $start)
                && ($end === null || $day <= $end)));
            $missing = [];
            foreach ($counted as $day) {
                $coverage[$day]['expected']++;
                if (isset($filed[(int) $person->getKey()][$day])) {
                    $coverage[$day]['filed']++;
                } else {
                    $missing[] = $day;
                }
            }
            if ($missing === []) {
                continue;
            }
            $entries[] = [
                'row' => [
                    'employee' => $person,
                    'name' => PortalSubmittedReportPresenter::memberName(
                        is_string($person->first_name) ? $person->first_name : null,
                        is_string($person->last_name) ? $person->last_name : null,
                    ),
                    'email' => $email,
                    'department' => trim((string) ($person->department ?? '')),
                ],
                'missing' => $missing,
            ];
        }
        $share = (float) config('products.report_reminders.min_filed_share', self::MIN_FILED_SHARE);
        foreach ($coverage as $day => $count) {
            $coverage[$day]['whole'] = $count['expected'] === 0 || $count['filed'] >= $share * $count['expected'];
        }

        return ['coverage' => $coverage, 'people' => $entries];
    }

    /**
     * Each department head's view of the plan: everyone else in their department who is missing a report.
     * Heads are the ones company_departments names, as every other head email reads them; an executive never gets one.
     *
     * @param  list<array{employee: Employee, name: string, email: string, department: string, days: list<string>}>  $plan
     * @return list<array{head: Employee, name: string, department: string, members: list<array{name: string, days: list<string>}>}>
     */
    public function summaries(array $plan): array
    {
        $departments = DB::connection('portal')->table('company_departments')
            ->whereNotNull('head_employee_id')
            ->orderBy('name')
            ->pluck('name');

        $summaries = [];
        foreach ($departments as $name) {
            $department = trim((string) $name);
            $head = PortalDepartments::headOf($department);
            if ($head === null || trim((string) ($head->email ?? '')) === '' || PortalRole::isExecutive($head->role, $head->role_level)) {
                continue;
            }
            $members = [];
            foreach ($plan as $row) {
                if (PortalPreview::department($row['department']) === PortalPreview::department($department) && ! $row['employee']->is($head)) {
                    $members[] = ['name' => $row['name'], 'days' => $row['days']];
                }
            }
            if ($members !== []) {
                $summaries[] = [
                    'head' => $head,
                    'name' => PortalSubmittedReportPresenter::memberName(
                        is_string($head->first_name) ? $head->first_name : null,
                        is_string($head->last_name) ? $head->last_name : null,
                    ),
                    'department' => $department,
                    'members' => $members,
                ];
            }
        }

        return $summaries;
    }

    /**
     * One outbox email per person, since each names their own days.
     *
     * @param  list<string>  $days
     */
    public function remind(Employee $recipient, array $days): void
    {
        [$subject, $body] = $this->letter($days);
        // Each day it names is marked on Missing Reports, so nobody reminds them by hand as well (2026-10-21).
        $this->messages->sendToMembers(new Employee, Request::create('/'), [(int) $recipient->getKey()], $subject, $body, PortalShellPath::LATE_REPORT, $days);
    }

    /**
     * @param  array{head: Employee, department: string, members: list<array{name: string, days: list<string>}>}  $summary
     */
    public function summarize(array $summary): void
    {
        [$subject, $body] = $this->summaryLetter($summary);
        $this->messages->sendToMembers(new Employee, Request::create('/'), [(int) $summary['head']->getKey()], $subject, $body, PortalShellPath::MISSING_REPORTS);
    }

    /**
     * A developer's preview: a letter straight to any address, kept out of the outbox and the bell.
     * Sent now rather than queued, so a refused send throws to the command instead of only reaching the log.
     *
     * @param  array{0: string, 1: string}  $letter  subject and body, from letter() or summaryLetter()
     */
    public function sample(string $to, array $letter): void
    {
        Mail::to($to)->send(PortalLetters::letter('notice', '[Test] '.$letter[0], PortalLetters::body(null, $letter[1])));
    }

    /**
     * @param  list<string>  $days
     * @return array{0: string, 1: string}
     */
    public function letter(array $days): array
    {
        $lines = array_map(static fn (string $day): string => '- '.Carbon::createFromFormat('Y-m-d', $day)->format('l, F j, Y'), $days);

        return [
            'Reminder: '.(count($days) === 1 ? 'a report' : count($days).' reports').' not yet filed',
            "Our records show you have not yet filed a report for:\n\n".implode("\n", $lines)
                ."\n\nPlease file them as late reports as soon as you can.",
        ];
    }

    /**
     * @param  array{department: string, members: list<array{name: string, days: list<string>}>}  $summary
     * @return array{0: string, 1: string}
     */
    public function summaryLetter(array $summary): array
    {
        $lines = array_map(static fn (array $member): string => '- '.$member['name'].' ('.count($member['days']).' missing): '
            .implode(', ', array_map(static fn (string $day): string => Carbon::createFromFormat('Y-m-d', $day)->format('D, M j'), $member['days'])), $summary['members']);

        return [
            'Reminder: '.$summary['department'].' team missing report dates',
            'These members of '.$summary['department']." have not yet filed a report in the last five work days:\n\n".implode("\n", $lines)
                ."\n\nEach of them has been reminded too. The full list is on Administration / Missing Reports.",
        ];
    }

    /**
     * The last WORK_DAYS weekdays before today that are not holidays, newest first.
     *
     * @return list<string>
     */
    private function workDays(Carbon $today): array
    {
        // Two calendar weeks always hold five work days unless holidays crowd them out, which is acceptable.
        $floor = $today->copy()->subDays(14)->toDateString();
        try {
            $holidays = array_flip(Holiday::query()
                ->whereNotNull('holiday_date')
                ->whereBetween('holiday_date', [$floor, $today->toDateString()])
                ->pluck('holiday_date')
                ->map(static fn (mixed $date): string => substr((string) $date, 0, 10))
                ->all());
        } catch (Throwable) {
            // Same fallback as PortalHolidays: without the calendar every weekday counts.
            $holidays = [];
        }

        $days = [];
        for ($day = $today->copy()->subDay(); count($days) < self::WORK_DAYS && $day->toDateString() >= $floor; $day->subDay()) {
            if (! $day->isWeekend() && ! isset($holidays[$day->toDateString()])) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    private function date(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : substr($text, 0, 10);
    }
}
