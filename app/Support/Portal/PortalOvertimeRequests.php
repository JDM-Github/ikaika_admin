<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\UserReport;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Requests / Overtime: hours worked past the regular day, filed against a day already reported.
 *
 * Overtime extends a record rather than creating one, so every date here must already carry the
 * member's own report. The requests table has no employee junction -- Airtable stored the member
 * as a name -- so ownership is the same composed name the rest of the portal matches on.
 */
final class PortalOvertimeRequests
{
    // Same eight-day strip the form offers, and the same window a report may still be changed in.
    public const WINDOW_DAYS = PortalSubmittedReports::MUTATION_WINDOW_DAYS;

    public const TYPE = 'Overtime';

    public const CACHE_TTL_SECONDS = 30;

    // Same read window as leave history, so User Requests reaches back equally far.
    private const MAX_RANGE_MONTHS = 24;

    private const MAX_AHEAD_MONTHS = 12;

    private const CACHE_VERSION_KEY = 'portal:requests:overtime:version';

    private const MAX_GROUPS = 8;

    private const MAX_ENTRIES = 20;

    private const MAX_HOURS = 8;

    private const MAX_REASON = 500;

    private const NEW_STATUS = 'Pending';

    private const CANCELLED_STATUS = 'Cancelled';

    private const CORE_TARGET = 'portal.requests';

    private const CORE_RESOURCE = 'requests.overtime';

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalSubmittedReports $reports,
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:requests:overtime:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $from, $to): array {
            return [
                'section' => 'requests',
                'resource' => 'overtime',
                'data' => $this->ownOvertime($actor, $from, $to),
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * File overtime for one or more days. One request carries the whole builder, so a multi-day
     * filing lands in a single transaction rather than half-saving.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $groups = $this->validatedGroups($request);
        $employeeId = (int) $actor->getKey();
        $name = $this->memberName($actor);

        $dates = [];
        foreach ($groups as $group) {
            $dates[] = $group['date'];
        }

        $this->assertDatesAreReported($employeeId, $dates);
        $this->assertDatesAreFree($actor, $dates);

        $projects = $this->ownProjectCatalog($employeeId);
        $createdAt = $this->timezone->now()->toDateTimeString();

        $resolved = [];
        foreach ($groups as $index => $group) {
            $resolved[$index] = $this->resolveProjects($group['entries'], $projects);
        }

        $insertedIds = $this->connection()->transaction(function () use (
            $groups,
            $resolved,
            $name,
            $createdAt,
        ): array {
            $ids = [];
            foreach ($groups as $index => $group) {
                $ids[] = $this->insertRequest($group, $resolved[$index], $name, $createdAt);
            }

            return $ids;
        });

        try {
            foreach ($groups as $index => $group) {
                $id = (string) $insertedIds[$index];
                $this->ledger->recordAdd(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreRecycleKey::overtimeRequest($employeeId, $group['date']),
                    self::CORE_TARGET,
                    [
                        'id' => $id,
                        'requestedFor' => $group['date'],
                        'hours' => $this->totalHours($group['entries']),
                        'entries' => $group['entries'],
                        'reason' => $group['reason'],
                    ],
                    self::CORE_RESOURCE,
                    $id,
                    $employeeId,
                    is_string($actor->id_no) ? $actor->id_no : null,
                );
                $this->audit->record(
                    $actor,
                    PortalLogAction::INSERT,
                    self::CORE_RESOURCE,
                    PortalActivityCopy::filedOvertime($group['date']),
                    $id,
                    $request,
                );
            }
        } catch (Throwable $error) {
            $this->deleteRequests($insertedIds);
            throw $error;
        }

        // The day now carries an overtime request, which is what the strip greys out next time.
        $this->reports->bumpCache();
        $this->bumpCache();

        return [
            'section' => 'requests',
            'resource' => 'overtime',
            'data' => $this->read($insertedIds),
        ];
    }

    /**
     * Rewrite one pending day. The builder reopens the same claim, so this is one group rather
     * than the create envelope's list.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function replace(Employee $actor, string $id, Request $request): array
    {
        $row = $this->ownPendingRow($actor, $id);
        $currentDate = $this->calendarDate($row->request_date ?? null);
        $group = $this->validatedSingleGroup($request, $currentDate);

        $employeeId = (int) $actor->getKey();
        $this->assertDatesAreReported($employeeId, [$group['date']]);
        $this->assertDatesAreFree($actor, [$group['date']], array_values(array_filter(
            [$currentDate],
            static fn (?string $date): bool => $date !== null,
        )));

        $projects = $this->ownProjectCatalog($employeeId);
        $projectIds = $this->resolveProjects($group['entries'], $projects);
        $hours = $this->totalHours($group['entries']);

        $this->connection()->transaction(function () use ($row, $group, $projectIds, $hours): void {
            $this->connection()->table('requests')->where('id', (int) $row->id)->update([
                'request_date' => $group['date'],
                'no_of_hours' => $hours,
                'reason' => $this->composedReason($group['reason'], $group['entries']),
            ]);
            $this->connection()->table('projects_requests')->where('request_id', (int) $row->id)->delete();
            foreach ($projectIds as $projectId) {
                $this->connection()->table('projects_requests')->insert([
                    'project_id' => $projectId,
                    'request_id' => (int) $row->id,
                ]);
            }
        });

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'id' => (string) (int) $row->id,
                'requestedFor' => $group['date'],
                'hours' => $hours,
                'entries' => $group['entries'],
                'reason' => $group['reason'],
            ],
            self::CORE_RESOURCE,
            (string) (int) $row->id,
            CoreRecycleKey::overtimeRequest($employeeId, $group['date']),
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::updatedOvertime($group['date']),
            (string) (int) $row->id,
            $request,
        );

        $this->reports->bumpCache();
        $this->bumpCache();

        $presented = $this->presentOvertime($this->connection()
            ->table('requests')
            ->select([
                'id',
                'request_date',
                'date_created',
                'reason',
                'status',
                'approver_remarks',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->where('id', (int) $row->id)
            ->first());
        if ($presented === null) {
            abort(500, 'The request was changed but could not be read back.');
        }

        return [
            'section' => 'requests',
            'resource' => 'overtime',
            'data' => $presented,
        ];
    }

    /**
     * Withdraw an overtime day. The row stays and its status changes: the history is a record of
     * what was asked for, and a cancelled request that vanished would read as one never filed.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function cancel(Employee $actor, string $id): array
    {
        $row = $this->ownPendingRow($actor, $id);
        $day = $this->calendarDate($row->request_date ?? null) ?? '';
        $employeeId = (int) $actor->getKey();

        $this->connection()->table('requests')
            ->where('id', (int) $row->id)
            ->update(['status' => self::CANCELLED_STATUS]);

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'id' => (string) (int) $row->id,
                'requestedFor' => $day,
                'status' => self::CANCELLED_STATUS,
            ],
            self::CORE_RESOURCE,
            (string) (int) $row->id,
            CoreRecycleKey::overtimeRequest($employeeId, $day),
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::cancelledOvertime($day),
            (string) (int) $row->id,
        );

        // A cancelled day is free again, for this form and for the report forms both.
        $this->bumpCache();
        $this->reports->bumpCache();

        $presented = $this->presentOvertime($this->connection()
            ->table('requests')
            ->select([
                'id',
                'request_date',
                'date_created',
                'reason',
                'status',
                'approver_remarks',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->where('id', (int) $row->id)
            ->first());
        if ($presented === null) {
            abort(500, 'The request was cancelled but could not be read back.');
        }

        return [
            'section' => 'requests',
            'resource' => 'overtime',
            'data' => $presented,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function read(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('requests')
            ->select(['id', 'request_date', 'no_of_hours', 'reason', 'status', 'type'])
            ->whereIn('id', $ids)
            ->orderBy('request_date')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id' => (string) (int) $row->id,
                'requestedFor' => $this->calendarDate($row->request_date ?? null),
                'hours' => (float) ($row->no_of_hours ?? 0),
                'reason' => (string) ($row->reason ?? ''),
                'status' => PortalSubmittedReportPresenter::requestStatus($row->status ?? self::NEW_STATUS),
                'type' => (string) ($row->type ?? self::TYPE),
            ];
        }

        return $data;
    }

    /**
     * @param  array{date: string, reason: string, entries: list<array<string, mixed>>}  $group
     * @param  list<int>  $projectIds
     */
    private function insertRequest(array $group, array $projectIds, string $name, string $createdAt): int
    {
        $id = (int) $this->connection()->table('requests')->insertGetId([
            'request_date' => $group['date'],
            'name' => $name,
            'no_of_hours' => $this->totalHours($group['entries']),
            'reason' => $this->composedReason($group['reason'], $group['entries']),
            'status' => self::NEW_STATUS,
            'type' => self::TYPE,
            'date_created' => $createdAt,
        ]);

        foreach ($projectIds as $projectId) {
            $this->connection()->table('projects_requests')->insert([
                'project_id' => $projectId,
                'request_id' => $id,
            ]);
        }

        return $id;
    }

    /**
     * The requests table holds one reason and no line items, so the breakdown the form collected
     * is written into it. Dropping it would leave an approver eight hours with nothing behind them.
     *
     * @param  list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>  $entries
     */
    private function composedReason(string $reason, array $entries): string
    {
        $lines = [$reason];
        foreach ($entries as $entry) {
            $lines[] = '- '.$entry['projectLabel'].' / '.$entry['activityLabel']
                .' / '.$this->hoursLabel($entry['hoursRendered']).'h';
        }

        return implode("\n", $lines);
    }

    private function hoursLabel(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>  $entries
     */
    private function totalHours(array $entries): float
    {
        $total = 0.0;
        foreach ($entries as $entry) {
            $total += $entry['hoursRendered'];
        }

        return round($total, 2);
    }

    /**
     * Overtime is filed against the member's own board, the same list the picker offers.
     *
     * @param  list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>  $entries
     * @param  array<string, int>  $projects
     * @return list<int>
     */
    private function resolveProjects(array $entries, array $projects): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $projectId = $projects[$this->lookupKey($entry['projectLabel'])] ?? null;
            if ($projectId === null) {
                abort(422, '"'.$entry['projectLabel'].'" is not one of your projects.');
            }
            if (! in_array($projectId, $ids, true)) {
                $ids[] = $projectId;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, int>
     */
    private function ownProjectCatalog(int $employeeId): array
    {
        $rows = $this->connection()
            ->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $employeeId)
            ->select(['projects.id', 'projects.project_number', 'projects.project_name'])
            ->get();

        $catalog = [];
        foreach ($rows as $row) {
            $label = PortalSubmittedReportPresenter::projectLabel(
                $row->project_number ?? null,
                $row->project_name ?? null,
            );
            $catalog[$this->lookupKey($label)] = (int) $row->id;
        }

        return $catalog;
    }

    /**
     * The rule the whole form is built around: overtime is hours past a day that was worked, so
     * a day with no report of the member's own has no regular day for the extra hours to extend.
     *
     * @param  list<string>  $dates
     */
    private function assertDatesAreReported(int $employeeId, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $rows = UserReport::query()
            ->toBase()
            ->select(['user_reports.report_date'])
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->whereIn('user_reports.report_date', $dates)
            ->groupBy('user_reports.report_date')
            ->get();

        $reported = [];
        foreach ($rows as $row) {
            $date = $this->calendarDate($row->report_date ?? null);
            if ($date !== null) {
                $reported[] = $date;
            }
        }

        foreach ($dates as $date) {
            if (! in_array($date, $reported, true)) {
                abort(422, 'There is no report for '.$date.', so there is no day to file overtime against.');
            }
        }
    }

    /**
     * @param  list<string>  $dates
     * @param  list<string>  $ignore  Dates this request already occupies, so an edit of the same day is not a clash with itself.
     */
    private function assertDatesAreFree(Employee $actor, array $dates, array $ignore = []): void
    {
        if ($dates === []) {
            return;
        }

        $taken = array_values(array_diff($this->reports->overtimeDays($actor, min($dates), max($dates)), $ignore));
        foreach ($dates as $date) {
            if (in_array($date, $taken, true)) {
                abort(422, 'Overtime is already filed for '.$date.'.');
            }
        }
    }

    /**
     * @return list<array{
     *     date: string,
     *     reason: string,
     *     entries: list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>
     * }>
     */
    private function validatedGroups(Request $request): array
    {
        $raw = $request->input('requests');
        if (! is_array($raw) || $raw === []) {
            abort(422, 'Add at least one day before filing.');
        }
        if (count($raw) > self::MAX_GROUPS) {
            abort(422, 'A single filing cannot cover more than 8 days.');
        }

        $groups = [];
        $seen = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                abort(422, 'Each day must be an object.');
            }
            $date = $this->parseDate(trim((string) ($item['requestDate'] ?? '')));
            $this->assertFilableDate($date);
            if (in_array($date, $seen, true)) {
                abort(422, 'That filing lists the same day twice.');
            }
            $seen[] = $date;
            $groups[] = [
                'date' => $date,
                'reason' => $this->validatedReason($item['reason'] ?? null),
                'entries' => $this->validatedEntryList($item['entries'] ?? null),
            ];
        }

        return $groups;
    }

    private function assertFilableDate(string $date, ?string $current = null): void
    {
        if ($current !== null && $date === $current) {
            return;
        }
        $day = $this->timezone->day($date);
        $today = $this->timezone->today();
        if ($day->gt($today) || $day->lt($today->copy()->subDays(self::WINDOW_DAYS))) {
            abort(422, 'Overtime can only be filed for today or the last seven days.');
        }
    }

    /**
     * @return array{date: string, reason: string, entries: list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>}
     */
    private function validatedSingleGroup(Request $request, ?string $currentDate): array
    {
        $date = $this->parseDate(trim((string) $request->input('requestDate', '')));
        $this->assertFilableDate($date, $currentDate);

        return [
            'date' => $date,
            'reason' => $this->validatedReason($request->input('reason')),
            'entries' => $this->validatedEntryList($request->input('entries')),
        ];
    }

    private function validatedReason(mixed $value): string
    {
        if (! is_scalar($value)) {
            abort(422, 'Overtime needs a reason.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            abort(422, 'Overtime needs a reason.');
        }
        if (strlen($text) > self::MAX_REASON) {
            abort(422, 'The reason cannot exceed 500 characters.');
        }

        return $text;
    }

    /**
     * @return list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>
     */
    private function validatedEntryList(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            abort(422, 'Add at least one entry before filing.');
        }
        if (count($raw) > self::MAX_ENTRIES) {
            abort(422, 'An overtime request cannot hold more than 20 entries.');
        }

        $entries = [];
        $totalHours = 0.0;
        foreach ($raw as $item) {
            if (! is_array($item)) {
                abort(422, 'Each entry must be an object.');
            }
            $project = trim((string) ($item['projectLabel'] ?? ''));
            $activity = trim((string) ($item['activityLabel'] ?? ''));
            if ($project === '' || $activity === '') {
                abort(422, 'Every entry needs a project and an activity.');
            }
            if (strlen($project) > 255 || strlen($activity) > 255) {
                abort(422, 'Project and activity labels are too long.');
            }
            $hours = $this->validatedHours($item['hoursRendered'] ?? null);
            $totalHours += $hours;
            $entries[] = [
                'projectLabel' => $project,
                'activityLabel' => $activity,
                'hoursRendered' => $hours,
                'elementChange' => $this->validatedElements($item['elementChange'] ?? 0),
            ];
        }

        if ($totalHours > self::MAX_HOURS + 0.001) {
            abort(422, 'An overtime request cannot exceed 8 hours.');
        }

        return $entries;
    }

    private function validatedHours(mixed $value): float
    {
        if (! is_numeric($value)) {
            abort(422, 'Hours must be a number.');
        }
        $hours = round((float) $value, 2);
        if ($hours <= 0 || $hours > self::MAX_HOURS) {
            abort(422, 'Hours must be between 0 and 8.');
        }

        return $hours;
    }

    private function validatedElements(mixed $value): float
    {
        if (! is_numeric($value)) {
            abort(422, 'Element change must be a number.');
        }
        $elements = round((float) $value, 2);
        if ($elements < 0 || $elements > 100000) {
            abort(422, 'Element change is out of range.');
        }

        return $elements;
    }

    private function parseDate(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value, $this->timezone->zone());
        } catch (Throwable) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon || $date->toDateString() !== $value) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $date->toDateString();
    }

    private function calendarDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownOvertime(Employee $actor, string $from, string $to): array
    {
        $name = $this->composedName($actor);
        if ($name === null) {
            return [];
        }

        $rows = $this->connection()
            ->table('requests')
            ->select([
                'id',
                'request_date',
                'date_created',
                'reason',
                'status',
                'approver_remarks',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->whereNotNull('request_date')
            ->whereBetween('request_date', [$from, $to])
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $presented = $this->presentOvertime($row);
            if ($presented === null) {
                continue;
            }
            $data[] = $presented;
        }

        return $data;
    }

    /**
     * The member's own overtime row, still undecided. Somebody else's is a 404 rather than a 403:
     * whose request it is is not this member's to learn.
     */
    private function ownPendingRow(Employee $actor, string $id): object
    {
        if (preg_match('/^\d{1,11}$/', trim($id)) !== 1) {
            abort(404, 'That request was not found.');
        }

        $name = $this->memberName($actor);
        $row = $this->connection()
            ->table('requests')
            ->select([
                'id',
                'request_date',
                'status',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->where('id', (int) $id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->first();

        if ($row === null) {
            abort(404, 'That request was not found.');
        }

        $kind = PortalSubmittedReportPresenter::occupancy(
            $row->type ?? null,
            $row->no_of_hours ?? null,
            $this->calendarDate($row->original_work_day ?? null),
            $this->calendarDate($row->offset_work_day ?? null),
        );
        if ($kind !== PortalSubmittedReportPresenter::OCCUPANCY_OVERTIME) {
            abort(404, 'That request was not found.');
        }

        if (PortalSubmittedReportPresenter::requestStatus($row->status ?? null) !== 'pending') {
            abort(422, 'Only a request nobody has decided on yet can be changed.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentOvertime(mixed $row): ?array
    {
        if (! is_object($row)) {
            return null;
        }
        $kind = PortalSubmittedReportPresenter::occupancy(
            $row->type ?? null,
            $row->no_of_hours ?? null,
            $this->calendarDate($row->original_work_day ?? null),
            $this->calendarDate($row->offset_work_day ?? null),
        );
        if ($kind !== PortalSubmittedReportPresenter::OCCUPANCY_OVERTIME) {
            return null;
        }
        $requestedOn = $this->calendarDate($row->request_date ?? null);
        if ($requestedOn === null) {
            return null;
        }
        $parsed = PortalSubmittedReportPresenter::splitComposedReason($this->text($row->reason ?? null));

        return [
            'id' => (string) (int) $row->id,
            'createdOn' => $this->calendarDate($row->date_created ?? null) ?? $requestedOn,
            'requestedOn' => $requestedOn,
            'hours' => round((float) ($row->no_of_hours ?? 0), 2),
            'remarks' => $parsed['remarks'],
            'status' => PortalSubmittedReportPresenter::requestStatus($row->status ?? null),
            'approverRemarks' => $this->text($row->approver_remarks ?? null),
            'projectLabel' => $parsed['projectLabel'],
            'entries' => $parsed['entries'],
        ];
    }

    private function memberName(Employee $actor): string
    {
        $name = $this->composedName($actor);
        if ($name === null) {
            abort(422, 'Your profile has no name on it, so a request cannot be filed under it.');
        }

        return $name;
    }

    private function composedName(Employee $actor): ?string
    {
        $name = PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );

        return $name === 'Member' ? null : $name;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(Request $request): array
    {
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));

        $to = $toRaw === ''
            ? $this->timezone->today()->addMonths(self::MAX_AHEAD_MONTHS)
            : $this->parseDay($toRaw);
        $floor = $to->copy()->subMonths(self::MAX_RANGE_MONTHS + self::MAX_AHEAD_MONTHS)->startOfMonth();
        $from = $fromRaw === '' ? $floor->copy() : $this->parseDay($fromRaw);

        if ($from->gt($to)) {
            abort(422, 'The from date must be on or before the to date.');
        }
        if ($from->lt($floor)) {
            $from = $floor;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function parseDay(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value, $this->timezone->zone());
        } catch (Throwable) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon || $date->toDateString() !== $value) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $date->startOfDay();
    }

    private function lookupKey(string $label): string
    {
        return strtolower(trim($label));
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteRequests(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection()->table('projects_requests')->whereIn('request_id', $ids)->delete();
        $this->connection()->table('requests')->whereIn('id', $ids)->delete();
    }

    private function connection(): Connection
    {
        return UserReport::query()->getConnection();
    }
}
