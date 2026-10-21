<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Administration / Missing Reports: who in the reader's reach has not filed a report, and the reminder
 * email for them.
 *
 * The roster is the page rule's own (PortalRules::people on administration.missing): active people,
 * never an executive, as far as the reach goes, and always the reader. Everyone's filed days are read
 * and cached once, then cut down to that roster here, so nobody outside the reach leaves the server.
 *
 * A reminder names its day and its people. It is refused for a weekend, a day still ahead, or anyone
 * outside the roster; whoever filed that day in the meantime is dropped rather than emailed. The
 * wording is fixed here, so the power to remind cannot send anything else.
 */
final class PortalMissingReports
{
    public const RULE = 'administration.missing';

    private const MAX_RECIPIENTS = 500;

    public function __construct(
        private readonly PortalSubmittedReports $reports,
        private readonly PortalEmailMessages $messages,
        private readonly PortalTimezone $timezone,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array{employeeId: string, name: string, email: ?string, isAdmin: bool, days: list<string>, earlierDay: ?string, reminders: list<array{day: string, sentAt: string, sentBy: ?string}>}>,
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        $filed = $this->reports->filedDaysByMember($request);
        $byId = [];
        foreach ($filed['data'] as $row) {
            $byId[(int) $row['employeeId']] = $row;
        }
        $reminders = $this->remindersByMember($filed['range']);

        $data = [];
        foreach (PortalRules::people($actor, $request, self::RULE) as $person) {
            $id = (int) $person->getKey();
            $email = trim((string) ($person->email ?? ''));
            $data[] = [
                'employeeId' => (string) $id,
                'name' => $this->nameOf($person),
                'email' => $email === '' ? null : $email,
                'isAdmin' => PortalRole::isAdmin($person->role, $person->role_level),
                'days' => $byId[$id]['days'] ?? [],
                'earlierDay' => $byId[$id]['earlierDay'] ?? null,
                'reminders' => $reminders[$id] ?? [],
            ];
        }

        return [
            'section' => 'administration',
            'resource' => 'missing-reports',
            'data' => $data,
            'range' => $filed['range'],
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function remind(Employee $actor, Request $request): array
    {
        $day = $this->validatedDay($request);
        $ids = $this->validatedIds($request);

        $roster = array_map(static fn (Employee $person): int => (int) $person->getKey(), PortalRules::people($actor, $request, self::RULE));
        if (array_diff($ids, $roster) !== []) {
            abort(422, 'Choose people from the list the page offers.');
        }

        $filed = DB::connection('portal')->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->whereIn('employees_user_reports.employee_id', $ids)
            ->whereDate('user_reports.report_date', $day)
            ->distinct()
            ->pluck('employees_user_reports.employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $recipients = array_values(array_diff($ids, $filed));
        if ($recipients === []) {
            abort(422, 'Everyone chosen has filed a report for that day.');
        }

        $label = $this->timezone->day($day)->format('l, F j, Y');
        // Today's is still the daily report; an earlier day is only fileable as a late one.
        $link = $day === $this->timezone->today()->toDateString() ? PortalShellPath::DAILY_REPORT : PortalShellPath::LATE_REPORT;

        return $this->messages->sendToMembers(
            $actor,
            $request,
            $recipients,
            'Reminder: report not yet filed for '.$label,
            'Our records show you have not yet filed a report for '.$label.'. Please submit it as soon as you can.',
            $link,
            [$day],
        );
    }

    /**
     * Who was emailed a reminder for a day in the range (2026-10-21, user), the latest one per person and
     * day, and by whom: nobody named for the scheduled run, which sends as the portal.
     *
     * @param  array{from: string, to: string}  $range
     * @return array<int, list<array{day: string, sentAt: string, sentBy: ?string}>>
     */
    private function remindersByMember(array $range): array
    {
        // A server not yet migrated shows no marks rather than no page.
        try {
            $rows = DB::connection('portal')->table('report_reminders as r')
                ->leftJoin('employees as e', 'e.id', '=', 'r.sent_by')
                ->whereBetween('r.report_date', [$range['from'], $range['to']])
                ->orderByDesc('r.date_created')
                ->orderByDesc('r.id')
                ->get(['r.employee_id', 'r.report_date', 'r.date_created', 'r.sent_by', 'e.first_name', 'e.last_name']);
        } catch (QueryException) {
            return [];
        }

        $byMember = [];
        $seen = [];
        foreach ($rows as $row) {
            $day = substr((string) $row->report_date, 0, 10);
            $key = $row->employee_id.'|'.$day;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $byMember[(int) $row->employee_id][] = [
                'day' => $day,
                'sentAt' => Carbon::parse((string) $row->date_created, 'UTC')->toIso8601String(),
                'sentBy' => $row->sent_by === null ? null : PortalSubmittedReportPresenter::memberName(
                    is_string($row->first_name) ? $row->first_name : null,
                    is_string($row->last_name) ? $row->last_name : null,
                ),
            ];
        }

        return $byMember;
    }

    private function validatedDay(Request $request): string
    {
        $value = trim((string) $request->input('day', ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            abort(422, 'Choose the day the reminder is for.');
        }
        $day = $this->timezone->day($value);
        if ($day->isWeekend()) {
            abort(422, 'Reports are not filed on weekends, so nobody is missing one.');
        }
        if ($day->greaterThan($this->timezone->today())) {
            abort(422, 'That day has not come yet.');
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private function validatedIds(Request $request): array
    {
        $raw = $request->input('employeeIds');
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_RECIPIENTS) {
            abort(422, 'Choose who the reminder is for.');
        }
        $ids = [];
        foreach ($raw as $value) {
            if (! is_numeric($value) || (int) $value <= 0) {
                abort(422, 'Choose people from the list the page offers.');
            }
            $ids[(int) $value] = true;
        }

        return array_keys($ids);
    }

    private function nameOf(Employee $person): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($person->first_name) ? $person->first_name : null,
            is_string($person->last_name) ? $person->last_name : null,
        );
    }
}
