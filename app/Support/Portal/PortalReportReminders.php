<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\ProjectEstimator\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 */
final class PortalReportReminders
{
    public const ZONE = 'Asia/Manila';

    public const WORK_DAYS = 5;

    public function __construct(
        private readonly PortalSubmittedReports $reports,
        private readonly PortalEmailMessages $messages,
    ) {}

    /**
     * Who is missing which work days in the window ending yesterday, never a day before they started,
     * after they left, or one their leave already keeps them out of.
     *
     * @return list<array{employee: Employee, name: string, email: string, days: list<string>}>
     */
    public function missing(Carbon $today): array
    {
        $window = $this->workDays($today->copy()->setTimezone(self::ZONE)->startOfDay());
        if ($window === []) {
            return [];
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

        $plan = [];
        $people = Employee::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'role', 'role_level', 'start_date', 'end_date'])
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
            $away = array_flip($this->reports->leaveDays($person, $from, $to));
            $days = array_values(array_filter($window, static fn (string $day): bool => ! isset($filed[(int) $person->getKey()][$day])
                && ! isset($away[$day])
                && ($start === null || $day >= $start)
                && ($end === null || $day <= $end)));
            if ($days === []) {
                continue;
            }
            $plan[] = [
                'employee' => $person,
                'name' => PortalSubmittedReportPresenter::memberName(
                    is_string($person->first_name) ? $person->first_name : null,
                    is_string($person->last_name) ? $person->last_name : null,
                ),
                'email' => $email,
                'days' => array_reverse($days),
            ];
        }

        return $plan;
    }

    /**
     * One outbox email per person, since each names their own days.
     *
     * @param  list<string>  $days
     */
    public function remind(Employee $recipient, array $days): void
    {
        [$subject, $body] = $this->wording($days);
        $this->messages->sendToMembers(new Employee, Request::create('/'), [(int) $recipient->getKey()], $subject, $body, PortalShellPath::LATE_REPORT);
    }

    /**
     * A developer's preview: the same letter straight to any address, kept out of the outbox and the bell.
     * Sent now rather than queued, so a refused send throws to the command instead of only reaching the log.
     *
     * @param  list<string>  $days
     */
    public function sample(string $to, array $days): void
    {
        [$subject, $body] = $this->wording($days);
        Mail::to($to)->send(PortalLetters::letter('notice', '[Test] '.$subject, PortalLetters::body(null, $body)));
    }

    /**
     * @param  list<string>  $days
     * @return array{0: string, 1: string}
     */
    private function wording(array $days): array
    {
        $lines = array_map(static fn (string $day): string => '- '.Carbon::createFromFormat('Y-m-d', $day)->format('l, F j, Y'), $days);

        return [
            'Reminder: '.(count($days) === 1 ? 'a report' : count($days).' reports').' not yet filed',
            "Our records show you have not yet filed a report for:\n\n".implode("\n", $lines)
                ."\n\nPlease file them as late reports as soon as you can.",
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
