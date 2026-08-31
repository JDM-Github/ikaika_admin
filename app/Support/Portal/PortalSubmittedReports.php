<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Recycle;
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
 * Reports / Submitted: the signed-in member's own timesheet lines, grouped by day and kind.
 */
final class PortalSubmittedReports
{
    public const CACHE_TTL_SECONDS = 30;

    public const MAX_RANGE_MONTHS = 24;

    // Same window the daily report date strip files against: today and the seven days behind it.
    public const MUTATION_WINDOW_DAYS = 7;

    private const MAX_ENTRIES = 20;

    private const CACHE_VERSION_KEY = 'portal:reports:submitted:version';

    private const LOOKUP_CHUNK = 500;

    private const CORE_TARGET = 'portal.user_reports';

    private const CORE_RESOURCE = 'reports.submitted';

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalRecycleBin $recycleBin,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{reports: int, days: int, daily: int, late: int, hours: float},
     *     range: array{from: string, to: string},
     *     leaveDays: list<string>,
     *     offsetDays: list<string>
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:reports:submitted:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $from, $to): array {
            return $this->build($actor, $from, $to);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function replace(Employee $actor, string $id, Request $request): array
    {
        [$date, $kind] = $this->parseGroupId($id);
        $this->assertMutableDate($date);

        $employeeId = (int) $actor->getKey();
        $existing = $this->ownedLines($employeeId, $date, $kind);
        if ($existing === []) {
            abort(404, 'That report was not found.');
        }

        $this->assertSoleOwner($employeeId, $this->lineIds($existing));

        $entries = $this->validatedEntries($request);
        $remarks = $this->validatedRemarks($request);
        $resolved = $this->resolveEntries($entries, $existing);

        $first = $existing[0];
        $lateSubmission = $first->late_submission ?? null;
        $approval = $first->approval ?? 'Approved';
        $created = $first->date_created ?? null;

        $this->connection()->transaction(function () use (
            $employeeId,
            $existing,
            $resolved,
            $date,
            $remarks,
            $lateSubmission,
            $approval,
            $created,
        ): void {
            $this->deleteLines($this->lineIds($existing));
            foreach ($resolved as $entry) {
                $this->insertLine(
                    $employeeId,
                    $date,
                    $entry,
                    $remarks,
                    $lateSubmission,
                    $approval,
                    $created,
                );
            }
        });

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'entries' => $entries,
                'remarks' => $remarks,
            ],
            self::CORE_RESOURCE,
            $id,
            CoreRecycleKey::submittedReport($employeeId, $date, $kind),
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->bumpCache();

        $fresh = $this->build($actor, $date, $date);
        foreach ($fresh['data'] as $row) {
            if (($row['id'] ?? null) === $id) {
                return [
                    'section' => 'reports',
                    'resource' => 'submitted',
                    'data' => $row,
                ];
            }
        }

        abort(500, 'The report was saved but could not be read back.');
    }

    public function destroy(Employee $actor, string $id): void
    {
        [$date, $kind] = $this->parseGroupId($id);
        $this->assertMutableDate($date);

        $employeeId = (int) $actor->getKey();
        $existing = $this->ownedLines($employeeId, $date, $kind);
        if ($existing === []) {
            abort(404, 'That report was not found.');
        }

        $ids = $this->lineIds($existing);
        $this->assertSoleOwner($employeeId, $ids);

        $written = $this->ledger->recycleDeleted(
            CoreLedger::PRODUCT_PORTAL,
            CoreRecycleKey::submittedReport($employeeId, $date, $kind),
            self::CORE_TARGET,
            $id,
            $this->recycleSnapshot($actor, $id, $date, $kind, $existing),
            self::CORE_RESOURCE,
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        try {
            $this->deleteLines($ids);
        } catch (Throwable $error) {
            $this->ledger->revert($written['recycle_id'], $written['action_id']);
            throw $error;
        }

        $this->bumpCache();
        $this->recycleBin->bumpCache();
    }

    /**
     * Put a recycled submitted report back. Logs action_type add and drops the recycle
     * row. The original delete action stays — restore is an add, not an erase.
     */
    public function restoreFromBin(Employee $actor, Recycle $row): void
    {
        if ($row->product !== CoreLedger::PRODUCT_PORTAL) {
            abort(404, 'That item was not found.');
        }

        $isSubmitted = $row->resource === self::CORE_RESOURCE
            || $row->database_target === self::CORE_TARGET;
        if (! $isSubmitted) {
            abort(422, 'This item cannot be restored from here.');
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        $rawLines = $payload['lines'] ?? null;
        if (! is_array($rawLines) || $rawLines === []) {
            abort(422, 'That snapshot has nothing to restore.');
        }

        $ownerId = $this->ownerFromRecycleKey((string) $row->recycle_key);
        if ($ownerId === null) {
            abort(422, 'That snapshot cannot be restored.');
        }

        [$date, $kind] = $this->parseGroupId((string) $row->record_id);

        if ($this->ownedLines($ownerId, $date, $kind) !== []) {
            abort(422, 'A report already exists for that day. Delete it from Submitted Reports first.');
        }

        $lines = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                abort(422, 'That snapshot is missing a project or activity and cannot be restored.');
            }
            $lines[] = $line;
        }

        $insertedIds = $this->connection()->transaction(function () use ($ownerId, $date, $lines): array {
            $ids = [];
            foreach ($lines as $line) {
                $ids[] = $this->insertSnapshotLine($ownerId, $date, $line);
            }

            return $ids;
        });

        try {
            $this->ledger->recordAdd(
                CoreLedger::PRODUCT_PORTAL,
                (string) $row->recycle_key,
                self::CORE_TARGET,
                [
                    'id' => (string) $row->record_id,
                    'kind' => $kind,
                    'submittedOn' => $date,
                    'restored' => true,
                ],
                self::CORE_RESOURCE,
                (string) $row->record_id,
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
        } catch (Throwable $error) {
            $this->deleteLines($insertedIds);
            throw $error;
        }

        $this->bumpCache();
        $this->recycleBin->bumpCache();
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{reports: int, days: int, daily: int, late: int, hours: float},
     *     range: array{from: string, to: string},
     *     leaveDays: list<string>,
     *     offsetDays: list<string>
     * }
     */
    private function build(Employee $actor, string $from, string $to): array
    {
        $lines = $this->lines((int) $actor->getKey(), $from, $to);
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = (int) $line->id;
        }

        $projects = $this->projectLabels($ids);
        $activities = $this->activityLabels($ids);
        $earnCodes = $this->earnCodeLabels($ids);

        $reference = PortalSubmittedReportPresenter::referenceCode($actor->id_no, $actor->getKey());
        $memberName = PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );

        $groups = [];
        foreach ($lines as $line) {
            $date = $this->calendarDate($line->report_date);
            if ($date === null) {
                continue;
            }
            $kind = PortalSubmittedReportPresenter::kind($line->late_submission ?? null);
            $key = $date.'-'.$kind;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'kind' => $kind,
                    'submittedOn' => $date,
                    'reason' => null,
                    'entries' => [],
                ];
            }
            $reason = PortalSubmittedReportPresenter::reason($line->remarks ?? null);
            if ($groups[$key]['reason'] === null && $reason !== null) {
                $groups[$key]['reason'] = $reason;
            }
            $id = (int) $line->id;
            $groups[$key]['entries'][] = PortalSubmittedReportPresenter::entry(
                (string) $id,
                $projects[$id] ?? [],
                $activities[$id] ?? [],
                $earnCodes[$id] ?? [],
                $line->hours_rendered ?? 0,
                $line->change_in_elements ?? 0,
            );
        }

        $rows = [];
        $dates = [];
        $daily = 0;
        $late = 0;
        $hours = 0.0;
        foreach ($groups as $key => $group) {
            $rows[] = PortalSubmittedReportPresenter::report(
                $key,
                $reference,
                $memberName,
                $group['kind'],
                $group['submittedOn'],
                $group['reason'],
                $group['entries'],
            );
            $dates[$group['submittedOn']] = true;
            if ($group['kind'] === PortalSubmittedReportPresenter::KIND_LATE) {
                $late++;
            } else {
                $daily++;
            }
            foreach ($group['entries'] as $entry) {
                $hours += (float) $entry['hoursRendered'];
            }
        }

        $occupancy = $this->occupancy($actor, $from, $to);

        return [
            'section' => 'reports',
            'resource' => 'submitted',
            'data' => $rows,
            'counts' => PortalSubmittedReportPresenter::counts(
                count($rows),
                count($dates),
                $daily,
                $late,
                $hours,
            ),
            'range' => ['from' => $from, 'to' => $to],
            'leaveDays' => $occupancy['leave'],
            'offsetDays' => $occupancy['offset'],
        ];
    }

    /**
     * @return list<object>
     */
    private function lines(int $employeeId, string $from, string $to): array
    {
        return UserReport::query()
            ->toBase()
            ->select([
                'user_reports.id',
                'user_reports.report_date',
                'user_reports.hours_rendered',
                'user_reports.change_in_elements',
                'user_reports.remarks',
                'user_reports.late_submission',
            ])
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->whereNotNull('user_reports.report_date')
            ->whereBetween('user_reports.report_date', [$from, $to])
            ->orderBy('user_reports.report_date', 'desc')
            ->orderBy('user_reports.id')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $reportIds
     * @return array<int, list<string>>
     */
    private function projectLabels(array $reportIds): array
    {
        $labels = [];
        foreach ($this->chunks($reportIds) as $chunk) {
            $rows = $this->connection()
                ->table('projects_user_reports as pivot')
                ->join('projects', 'projects.id', '=', 'pivot.project_id')
                ->whereIn('pivot.user_report_id', $chunk)
                ->select([
                    'pivot.user_report_id',
                    'projects.project_number',
                    'projects.project_name',
                ])
                ->get();

            foreach ($rows as $row) {
                $labels[(int) $row->user_report_id][] = PortalSubmittedReportPresenter::projectLabel(
                    $row->project_number ?? null,
                    $row->project_name ?? null,
                );
            }
        }

        return $labels;
    }

    /**
     * @param  list<int>  $reportIds
     * @return array<int, list<string>>
     */
    private function activityLabels(array $reportIds): array
    {
        $labels = [];
        foreach ($this->chunks($reportIds) as $chunk) {
            $rows = $this->connection()
                ->table('user_reports_activity_codes as pivot')
                ->join('activity_codes', 'activity_codes.id', '=', 'pivot.activity_code_id')
                ->whereIn('pivot.user_report_id', $chunk)
                ->select([
                    'pivot.user_report_id',
                    'activity_codes.name',
                    'activity_codes.id_no',
                ])
                ->get();

            foreach ($rows as $row) {
                $labels[(int) $row->user_report_id][] = PortalSubmittedReportPresenter::activityLabel(
                    $row->name ?? null,
                    $row->id_no ?? null,
                );
            }
        }

        return $labels;
    }

    /**
     * @param  list<int>  $reportIds
     * @return array<int, list<string>>
     */
    private function earnCodeLabels(array $reportIds): array
    {
        $labels = [];
        foreach ($this->chunks($reportIds) as $chunk) {
            $rows = $this->connection()
                ->table('user_reports_earn_codes as pivot')
                ->join('earn_codes', 'earn_codes.id', '=', 'pivot.earn_code_id')
                ->whereIn('pivot.user_report_id', $chunk)
                ->select([
                    'pivot.user_report_id',
                    'earn_codes.description',
                ])
                ->get();

            foreach ($rows as $row) {
                $labels[(int) $row->user_report_id][] = PortalSubmittedReportPresenter::earnCodeLabel(
                    $row->description ?? null,
                );
            }
        }

        return $labels;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(Request $request): array
    {
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));

        $to = $toRaw === '' ? Carbon::today() : $this->parseDate($toRaw);
        $floor = $to->copy()->subMonths(self::MAX_RANGE_MONTHS)->startOfMonth();
        $from = $fromRaw === '' ? $floor->copy() : $this->parseDate($fromRaw);

        if ($from->gt($to)) {
            abort(422, 'The from date must be on or before the to date.');
        }

        if ($from->lt($floor)) {
            $from = $floor;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function parseDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon || $date->toDateString() !== $value) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $date->startOfDay();
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
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    private function chunks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_chunk($ids, self::LOOKUP_CHUNK);
    }

    private function connection(): Connection
    {
        return UserReport::query()->getConnection();
    }

    /**
     * The signed-in member's own leave and offset days in the window. Requests have no employee
     * junction — Airtable stored the member as `name` — so the match is the same composed name
     * the payload already sends. Cancelled rows are ignored; pending and rejected still occupy
     * the day, because a request that was filed is why that weekday is not missing.
     *
     * @return array{leave: list<string>, offset: list<string>}
     */
    private function occupancy(Employee $actor, string $from, string $to): array
    {
        $name = PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
        if ($name === 'Member') {
            return ['leave' => [], 'offset' => []];
        }

        $rows = $this->connection()
            ->table('requests')
            ->select([
                'type',
                'status',
                'no_of_hours',
                'request_date',
                'original_work_day',
                'offset_work_day',
            ])
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) not in ('cancelled', 'canceled', 'withdrawn')")
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('request_date', [$from, $to])
                    ->orWhereBetween('original_work_day', [$from, $to])
                    ->orWhereBetween('offset_work_day', [$from, $to]);
            })
            ->get();

        $leave = [];
        $offset = [];
        foreach ($rows as $row) {
            $kind = PortalSubmittedReportPresenter::occupancy(
                $row->type ?? null,
                $row->no_of_hours ?? null,
                $this->calendarDate($row->original_work_day ?? null),
                $this->calendarDate($row->offset_work_day ?? null),
            );
            if ($kind === PortalSubmittedReportPresenter::OCCUPANCY_LEAVE) {
                $date = $this->calendarDate($row->request_date ?? null);
                if ($date !== null) {
                    $leave[] = $date;
                }

                continue;
            }
            if ($kind !== PortalSubmittedReportPresenter::OCCUPANCY_OFFSET) {
                continue;
            }
            $work = $this->calendarDate($row->original_work_day ?? null);
            $off = $this->calendarDate($row->offset_work_day ?? null);
            if ($work !== null) {
                $offset[] = $work;
            }
            if ($off !== null) {
                $offset[] = $off;
            }
        }

        return [
            'leave' => PortalSubmittedReportPresenter::uniqueDates($leave),
            'offset' => PortalSubmittedReportPresenter::uniqueDates($offset),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseGroupId(string $id): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(daily|late)$/', $id, $match) !== 1) {
            abort(404, 'That report was not found.');
        }

        return [$match[1], $match[2]];
    }

    private function assertMutableDate(string $date): void
    {
        $day = $this->parseDate($date);
        $today = Carbon::today();
        $floor = $today->copy()->subDays(self::MUTATION_WINDOW_DAYS);
        if ($day->gt($today) || $day->lt($floor)) {
            abort(422, 'Reports can only be changed for today or the last seven days.');
        }
    }

    /**
     * @return list<object>
     */
    private function ownedLines(int $employeeId, string $date, string $kind): array
    {
        $rows = UserReport::query()
            ->toBase()
            ->select([
                'user_reports.id',
                'user_reports.report_date',
                'user_reports.hours_rendered',
                'user_reports.change_in_elements',
                'user_reports.remarks',
                'user_reports.late_submission',
                'user_reports.approval',
                'user_reports.date_created',
            ])
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->where('user_reports.report_date', $date)
            ->orderBy('user_reports.id')
            ->get()
            ->all();

        $owned = [];
        foreach ($rows as $row) {
            if (PortalSubmittedReportPresenter::kind($row->late_submission ?? null) === $kind) {
                $owned[] = $row;
            }
        }

        return $owned;
    }

    /**
     * @param  list<object>  $lines
     * @return list<int>
     */
    private function lineIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = (int) $line->id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $reportIds
     */
    private function assertSoleOwner(int $employeeId, array $reportIds): void
    {
        if ($reportIds === []) {
            return;
        }

        $shared = $this->connection()
            ->table('employees_user_reports')
            ->whereIn('user_report_id', $reportIds)
            ->where('employee_id', '!=', $employeeId)
            ->exists();

        if ($shared) {
            abort(422, 'This report is linked to another member and cannot be changed here.');
        }
    }

    /**
     * Restore lines keep the ids a later insert needs. The generated report is
     * what View reads, so a deleted filing still shows kind, entries, and remarks.
     *
     * @param  list<object>  $lines
     * @return array<string, mixed>
     */
    private function recycleSnapshot(Employee $actor, string $id, string $date, string $kind, array $lines): array
    {
        $ids = $this->lineIds($lines);
        $junctions = $this->lineJunctions($ids);
        $projects = $this->projectLabels($ids);
        $activities = $this->activityLabels($ids);
        $earnCodes = $this->earnCodeLabels($ids);

        $reason = null;
        $entries = [];
        $snapshotLines = [];
        foreach ($lines as $line) {
            $lineId = (int) $line->id;
            $lineReason = PortalSubmittedReportPresenter::reason($line->remarks ?? null);
            if ($reason === null && $lineReason !== null) {
                $reason = $lineReason;
            }
            $entries[] = PortalSubmittedReportPresenter::entry(
                (string) $lineId,
                $projects[$lineId] ?? [],
                $activities[$lineId] ?? [],
                $earnCodes[$lineId] ?? [],
                $line->hours_rendered ?? 0,
                $line->change_in_elements ?? 0,
            );
            $snapshotLines[] = [
                'id' => $lineId,
                'report_date' => $this->calendarDate($line->report_date) ?? $date,
                'hours_rendered' => $line->hours_rendered ?? 0,
                'change_in_elements' => $line->change_in_elements ?? 0,
                'remarks' => $line->remarks ?? null,
                'late_submission' => $line->late_submission ?? null,
                'approval' => $line->approval ?? null,
                'date_created' => $line->date_created ?? null,
                'project_ids' => $junctions['projects'][$lineId] ?? [],
                'activity_code_ids' => $junctions['activities'][$lineId] ?? [],
                'earn_code_ids' => $junctions['earn_codes'][$lineId] ?? [],
            ];
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'submittedOn' => $date,
            'title' => PortalRecycleBinPresenter::title(null, $kind),
            'lines' => $snapshotLines,
            'report' => PortalSubmittedReportPresenter::report(
                $id,
                PortalSubmittedReportPresenter::referenceCode($actor->id_no, $actor->getKey()),
                PortalSubmittedReportPresenter::memberName(
                    is_string($actor->first_name) ? $actor->first_name : null,
                    is_string($actor->last_name) ? $actor->last_name : null,
                ),
                $kind,
                $date,
                $reason,
                $entries,
            ),
        ];
    }

    /**
     * @param  list<int>  $reportIds
     * @return array{projects: array<int, list<int>>, activities: array<int, list<int>>, earn_codes: array<int, list<int>>}
     */
    private function lineJunctions(array $reportIds): array
    {
        $projects = [];
        $activities = [];
        $earnCodes = [];
        if ($reportIds === []) {
            return ['projects' => $projects, 'activities' => $activities, 'earn_codes' => $earnCodes];
        }

        foreach ($this->connection()->table('projects_user_reports')->select(['user_report_id', 'project_id'])->whereIn('user_report_id', $reportIds)->get() as $row) {
            $projects[(int) $row->user_report_id][] = (int) $row->project_id;
        }
        foreach ($this->connection()->table('user_reports_activity_codes')->select(['user_report_id', 'activity_code_id'])->whereIn('user_report_id', $reportIds)->get() as $row) {
            $activities[(int) $row->user_report_id][] = (int) $row->activity_code_id;
        }
        foreach ($this->connection()->table('user_reports_earn_codes')->select(['user_report_id', 'earn_code_id'])->whereIn('user_report_id', $reportIds)->get() as $row) {
            $earnCodes[(int) $row->user_report_id][] = (int) $row->earn_code_id;
        }

        return ['projects' => $projects, 'activities' => $activities, 'earn_codes' => $earnCodes];
    }

    /**
     * @param  list<int>  $reportIds
     */
    private function deleteLines(array $reportIds): void
    {
        if ($reportIds === []) {
            return;
        }

        $this->connection()->table('user_reports')->whereIn('id', $reportIds)->delete();
    }

    /**
     * @return list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>
     */
    private function validatedEntries(Request $request): array
    {
        $raw = $request->input('entries');
        if (! is_array($raw) || $raw === []) {
            abort(422, 'Add at least one entry before saving.');
        }
        if (count($raw) > self::MAX_ENTRIES) {
            abort(422, 'A report cannot hold more than 20 entries.');
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
            $elements = $this->validatedElements($item['elementChange'] ?? 0);
            $totalHours += $hours;
            $entries[] = [
                'projectLabel' => $project,
                'activityLabel' => $activity,
                'hoursRendered' => $hours,
                'elementChange' => $elements,
            ];
        }

        if ($totalHours > 8 + 0.001) {
            abort(422, 'A report cannot exceed 8 hours.');
        }

        return $entries;
    }

    private function validatedHours(mixed $value): float
    {
        if (! is_numeric($value)) {
            abort(422, 'Hours rendered must be a number.');
        }
        $hours = round((float) $value, 2);
        if ($hours <= 0 || $hours > 8) {
            abort(422, 'Each entry needs hours above zero and no more than 8.');
        }

        return $hours;
    }

    private function validatedElements(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (! is_numeric($value)) {
            abort(422, 'Changed items must be a number.');
        }
        $elements = round((float) $value, 2);
        if ($elements < 0) {
            abort(422, 'Changed items cannot be negative.');
        }

        return $elements;
    }

    private function validatedRemarks(Request $request): ?string
    {
        if (! $request->exists('remarks') || $request->input('remarks') === null) {
            return null;
        }
        $value = trim((string) $request->input('remarks'));
        if (strlen($value) > 500) {
            abort(422, 'Remarks cannot exceed 500 characters.');
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>  $entries
     * @param  list<object>  $existing
     * @return list<array{projectId: int, activityCodeId: int, earnCodeId: ?int, hoursRendered: float, elementChange: float}>
     */
    private function resolveEntries(array $entries, array $existing): array
    {
        $projects = $this->projectCatalog();
        $activities = $this->activityCatalog();
        $earnIds = $this->earnIdsFor($this->lineIds($existing));
        $fallbackEarn = $earnIds[0] ?? $this->regularEarnCodeId();

        $resolved = [];
        foreach ($entries as $index => $entry) {
            $projectId = $projects[$this->lookupKey($entry['projectLabel'])] ?? null;
            $activityId = $activities[$this->lookupKey($entry['activityLabel'])] ?? null;
            if ($projectId === null || $activityId === null) {
                abort(422, 'Every entry needs a known project and activity.');
            }
            $resolved[] = [
                'projectId' => $projectId,
                'activityCodeId' => $activityId,
                'earnCodeId' => $earnIds[$index] ?? $fallbackEarn,
                'hoursRendered' => $entry['hoursRendered'],
                'elementChange' => $entry['elementChange'],
            ];
        }

        return $resolved;
    }

    /**
     * @return array<string, int>
     */
    private function projectCatalog(): array
    {
        $rows = $this->connection()
            ->table('projects')
            ->select(['id', 'project_number', 'project_name'])
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
     * @return array<string, int>
     */
    private function activityCatalog(): array
    {
        $rows = $this->connection()
            ->table('activity_codes')
            ->select(['id', 'name', 'id_no'])
            ->get();

        $catalog = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $name = trim((string) ($row->name ?? ''));
            $code = trim((string) ($row->id_no ?? ''));
            $presenter = PortalSubmittedReportPresenter::activityLabel(
                $row->name ?? null,
                $row->id_no ?? null,
            );
            foreach ([$presenter, $name, $code] as $label) {
                if ($label !== '') {
                    $catalog[$this->lookupKey($label)] = $id;
                }
            }
            if ($code !== '' && $name !== '') {
                $catalog[$this->lookupKey($code.' - '.$name)] = $id;
            }
        }

        return $catalog;
    }

    /**
     * @param  list<int>  $reportIds
     * @return list<int>
     */
    private function earnIdsFor(array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('user_reports_earn_codes')
            ->whereIn('user_report_id', $reportIds)
            ->select(['user_report_id', 'earn_code_id'])
            ->orderBy('user_report_id')
            ->get();

        $byReport = [];
        foreach ($rows as $row) {
            $reportId = (int) $row->user_report_id;
            if (! isset($byReport[$reportId])) {
                $byReport[$reportId] = (int) $row->earn_code_id;
            }
        }

        $ordered = [];
        foreach ($reportIds as $id) {
            if (isset($byReport[$id])) {
                $ordered[] = $byReport[$id];
            }
        }

        return $ordered;
    }

    private function regularEarnCodeId(): ?int
    {
        $row = $this->connection()
            ->table('earn_codes')
            ->select(['id', 'description'])
            ->orderBy('id')
            ->get();

        foreach ($row as $candidate) {
            $label = strtolower(PortalSubmittedReportPresenter::earnCodeLabel($candidate->description ?? null));
            if (str_contains($label, 'regular')) {
                return (int) $candidate->id;
            }
        }

        $first = $row->first();

        return $first === null ? null : (int) $first->id;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function insertSnapshotLine(int $employeeId, string $date, array $line): int
    {
        $projectIds = $this->intIds($line['project_ids'] ?? []);
        $activityIds = $this->intIds($line['activity_code_ids'] ?? []);
        if ($projectIds === [] || $activityIds === []) {
            abort(422, 'That snapshot is missing a project or activity and cannot be restored.');
        }

        $reportDate = $this->calendarDate($line['report_date'] ?? null) ?? $date;
        $id = (int) $this->connection()->table('user_reports')->insertGetId([
            'report_date' => $reportDate,
            'hours_rendered' => is_numeric($line['hours_rendered'] ?? null) ? $line['hours_rendered'] : 0,
            'change_in_elements' => is_numeric($line['change_in_elements'] ?? null) ? $line['change_in_elements'] : 0,
            'remarks' => $line['remarks'] ?? null,
            'late_submission' => $line['late_submission'] ?? null,
            'approval' => $line['approval'] ?? null,
            'date_created' => $line['date_created'] ?? null,
        ]);

        $this->connection()->table('employees_user_reports')->insert([
            'employee_id' => $employeeId,
            'user_report_id' => $id,
        ]);
        foreach ($projectIds as $projectId) {
            $this->connection()->table('projects_user_reports')->insert([
                'project_id' => $projectId,
                'user_report_id' => $id,
            ]);
        }
        foreach ($activityIds as $activityId) {
            $this->connection()->table('user_reports_activity_codes')->insert([
                'user_report_id' => $id,
                'activity_code_id' => $activityId,
            ]);
        }
        foreach ($this->intIds($line['earn_code_ids'] ?? []) as $earnId) {
            $this->connection()->table('user_reports_earn_codes')->insert([
                'user_report_id' => $id,
                'earn_code_id' => $earnId,
            ]);
        }

        return $id;
    }

    private function ownerFromRecycleKey(string $key): ?int
    {
        if (preg_match('/^(\d+):\d{4}-\d{2}-\d{2}-(daily|late)$/', $key, $match) !== 1) {
            return null;
        }

        $id = (int) $match[1];

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<int>
     */
    private function intIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array{projectId: int, activityCodeId: int, earnCodeId: ?int, hoursRendered: float, elementChange: float}  $entry
     */
    private function insertLine(
        int $employeeId,
        string $date,
        array $entry,
        ?string $remarks,
        mixed $lateSubmission,
        mixed $approval,
        mixed $created,
    ): void {
        $id = (int) $this->connection()->table('user_reports')->insertGetId([
            'report_date' => $date,
            'hours_rendered' => $entry['hoursRendered'],
            'change_in_elements' => $entry['elementChange'],
            'remarks' => $remarks,
            'late_submission' => $lateSubmission,
            'approval' => $approval,
            'date_created' => $created,
        ]);

        $this->connection()->table('employees_user_reports')->insert([
            'employee_id' => $employeeId,
            'user_report_id' => $id,
        ]);
        $this->connection()->table('projects_user_reports')->insert([
            'project_id' => $entry['projectId'],
            'user_report_id' => $id,
        ]);
        $this->connection()->table('user_reports_activity_codes')->insert([
            'user_report_id' => $id,
            'activity_code_id' => $entry['activityCodeId'],
        ]);
        if ($entry['earnCodeId'] !== null) {
            $this->connection()->table('user_reports_earn_codes')->insert([
                'user_report_id' => $id,
                'earn_code_id' => $entry['earnCodeId'],
            ]);
        }
    }

    private function lookupKey(string $label): string
    {
        return strtolower(trim($label));
    }
}
