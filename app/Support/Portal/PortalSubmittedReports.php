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

    // One POST files the whole builder, and the builder holds at most the eight-day date strip.
    private const MAX_GROUPS = 8;

    private const CACHE_VERSION_KEY = 'portal:reports:submitted:version';

    private const LOOKUP_CHUNK = 500;

    private const CORE_TARGET = 'portal.user_reports';

    private const CORE_RESOURCE = 'reports.submitted';

    // A freshly filed report has not been through anyone yet; replace() keeps whatever it finds.
    private const NEW_APPROVAL = 'Pending';

    // A freshly flagged report has not been reviewed yet either; replace() keeps whatever it finds.
    private const NEW_FLAG_STATUS = 'Pending';

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalRecycleBin $recycleBin,
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
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

    /**
     * Manage / Reports: the same calendar as Submitted Reports, for one chosen member.
     *
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{reports: int, days: int, daily: int, late: int, hours: float},
     *     range: array{from: string, to: string},
     *     leaveDays: list<string>,
     *     offsetDays: list<string>,
     *     employees: list<array{value: string, label: string}>,
     *     employeeId: string
     * }
     */
    public function listManaged(Employee $actor, Request $request): array
    {
        $subject = $this->managedSubject($actor, $request);
        $page = $this->list($subject, $request);
        $page['section'] = 'manage';
        $page['resource'] = 'reports';
        $page['employees'] = $this->roster();
        $page['employeeId'] = (string) $subject->getKey();

        return $page;
    }

    /**
     * Reports / Flagged: the signed-in member's own reports that were flagged at filing time.
     * Same cache version as list()/bumpCache() -- a decided flag or an edited report must
     * invalidate both.
     *
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     range: array{from: string, to: string}
     * }
     */
    public function listFlagged(Employee $actor, Request $request): array
    {
        [$from, $to] = $this->dateRange($request);
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:reports:flagged:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $from, $to): array {
            return [
                'section' => 'reports',
                'resource' => 'flagged',
                'data' => $this->flaggedReports($from, $to, (int) $actor->getKey()),
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    /**
     * Every report flagged at filing time (employees_user_reports.is_flag), for the given window.
     * Grouped by (employee, date, kind) -- the exact unit create() flagged together and the unit a
     * decision must land on as one, the same way build() groups one member's own lines.
     *
     * $onlyEmployeeId scopes to one member and nulls memberName in the output -- the same
     * "null means the signed-in member filed it" convention LeaveRequestModel uses on the portal.
     * Left null, every flagged report in range comes back with its real name, for the approver
     * queue PortalManageRequests bundles this into.
     *
     * @return list<array<string, mixed>>
     */
    public function flaggedReports(string $from, string $to, ?int $onlyEmployeeId = null): array
    {
        $query = $this->connection()
            ->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->leftJoin('employees', 'employees.id', '=', 'employees_user_reports.employee_id')
            ->where('employees_user_reports.is_flag', 1)
            ->whereNotNull('user_reports.report_date')
            ->whereBetween('user_reports.report_date', [$from, $to])
            ->select([
                'user_reports.id',
                'user_reports.report_date',
                'user_reports.hours_rendered',
                'user_reports.change_in_elements',
                'user_reports.remarks',
                'user_reports.late_submission',
                'employees_user_reports.employee_id',
                'employees_user_reports.flag_status',
                'employees_user_reports.flag_remarks',
                'employees.first_name',
                'employees.last_name',
                'employees.id_no',
            ]);
        if ($onlyEmployeeId !== null) {
            $query->where('employees_user_reports.employee_id', $onlyEmployeeId);
        }
        $rows = $query
            ->orderBy('user_reports.report_date', 'desc')
            ->orderBy('user_reports.id')
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }
        $projects = $this->projectLabels($ids);
        $activities = $this->activityLabels($ids);
        $earnCodes = $this->earnCodeLabels($ids);

        $groups = [];
        foreach ($rows as $row) {
            $date = $this->calendarDate($row->report_date);
            if ($date === null) {
                continue;
            }
            $kind = PortalSubmittedReportPresenter::kind($row->late_submission ?? null);
            $employeeId = (int) $row->employee_id;
            $key = $employeeId.'|'.$date.'|'.$kind;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'ids' => [],
                    'kind' => $kind,
                    'submittedOn' => $date,
                    'reason' => null,
                    'entries' => [],
                    'flagStatus' => $this->text($row->flag_status ?? null) ?? 'Pending',
                    'flagRemarks' => $this->text($row->flag_remarks ?? null),
                    'referenceCode' => PortalSubmittedReportPresenter::referenceCode(
                        $row->id_no ?? null,
                        $employeeId,
                    ),
                    // Own view names nobody; the approver bundle names everybody.
                    'memberName' => $onlyEmployeeId !== null ? null : PortalSubmittedReportPresenter::memberName(
                        is_string($row->first_name ?? null) ? $row->first_name : null,
                        is_string($row->last_name ?? null) ? $row->last_name : null,
                    ),
                ];
            }
            $reason = PortalSubmittedReportPresenter::reason($row->remarks ?? null);
            if ($groups[$key]['reason'] === null && $reason !== null) {
                $groups[$key]['reason'] = $reason;
            }
            $id = (int) $row->id;
            $groups[$key]['ids'][] = $id;
            $groups[$key]['entries'][] = PortalSubmittedReportPresenter::entry(
                (string) $id,
                $projects[$id] ?? [],
                $activities[$id] ?? [],
                $earnCodes[$id] ?? [],
                $row->hours_rendered ?? 0,
                $row->change_in_elements ?? 0,
            );
        }

        $data = [];
        foreach ($groups as $group) {
            $data[] = [
                'id' => (string) min($group['ids']),
                'referenceCode' => $group['referenceCode'],
                'memberName' => $group['memberName'],
                'kind' => $group['kind'],
                'submittedOn' => $group['submittedOn'],
                'reason' => $group['reason'],
                'status' => PortalSubmittedReportPresenter::requestStatus($group['flagStatus']),
                'approverRemarks' => $group['flagRemarks'],
                'entries' => $group['entries'],
            ];
        }

        return $data;
    }

    /**
     * The days an overtime request already claims. Public because overtime is filed elsewhere but
     * decided by the same occupancy read, and duplicating that query would let the two drift.
     *
     * @return list<string>
     */
    public function overtimeDays(Employee $actor, string $from, string $to): array
    {
        return $this->occupancy($actor, $from, $to)['overtime'];
    }

    /**
     * Both days of an offset pair: the day worked and the day taken off. Public because offset
     * is filed elsewhere but decided by the same occupancy read.
     *
     * @return list<string>
     */
    public function offsetDays(Employee $actor, string $from, string $to): array
    {
        return $this->occupancy($actor, $from, $to)['offset'];
    }

    /**
     * The days leave already keeps the member out of the office -- refused requests excluded, the
     * same subset a report may not be filed against.
     *
     * @return list<string>
     */
    public function leaveDays(Employee $actor, string $from, string $to): array
    {
        return $this->occupancy($actor, $from, $to)['blocked'];
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);

        // The dashboard reads this month's reports; a filed or deleted day must refresh it too.
        $homeVersion = (int) Cache::get(PortalHome::CACHE_VERSION_KEY, 1);
        Cache::forever(PortalHome::CACHE_VERSION_KEY, $homeVersion + 1);
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
        // Carried over, not recomputed: a flag is set once at filing time, and editing a report's
        // entries must not silently re-derive it from whatever the employee's warning status
        // happens to be today, nor reset a reviewer's already-recorded flag_status back to Pending.
        $isFlagged = (bool) ($first->is_flag ?? false);
        $flagStatus = (string) ($first->flag_status ?? 'Pending');

        $this->connection()->transaction(function () use (
            $employeeId,
            $existing,
            $resolved,
            $date,
            $remarks,
            $lateSubmission,
            $approval,
            $created,
            $isFlagged,
            $flagStatus,
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
                    $isFlagged,
                    $flagStatus,
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
        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::updatedReport($kind, $date),
            $id,
            $request,
            [
                'kind' => $kind,
                'submittedOn' => $date,
                'remarks' => $remarks,
                'entries' => $entries,
            ],
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

    /**
     * The days the member has already filed, as {date, kind}. The date strip and the late
     * calendar grey those out, so a second report for the same day is never offered. Kept
     * apart from list() on purpose: this reads two indexed columns and joins nothing.
     *
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array{date: string, kind: string}>,
     *     leaveDays: list<string>,
     *     overtimeDays: list<string>,
     *     offsetDays: list<string>,
     *     range: array{from: string, to: string}
     * }
     */
    public function days(Employee $actor, Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:reports:submitted:days:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $from, $to): array {
            // One occupancy read answers leave, overtime, and both days of an offset pair.
            $occupancy = $this->occupancy($actor, $from, $to);

            return [
                'section' => 'reports',
                'resource' => 'submitted',
                'data' => $this->filedDays((int) $actor->getKey(), $from, $to),
                'leaveDays' => $occupancy['blocked'],
                'overtimeDays' => $occupancy['overtime'],
                'offsetDays' => $occupancy['offset'],
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    /**
     * File a new daily or late report. The body carries one kind and one group per day, so the
     * whole builder lands in a single transaction rather than one request per day half-saving.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $kind = $this->validatedKind($request);
        $groups = $this->validatedGroups($request, $kind);
        $employeeId = (int) $actor->getKey();

        $dates = [];
        foreach ($groups as $group) {
            $dates[] = $group['date'];
        }
        $this->assertDatesAreFree($employeeId, $dates);

        $this->assertDatesAreWorked($actor, $dates);

        $lateSubmission = $kind === PortalSubmittedReportPresenter::KIND_LATE ? 'Yes' : null;
        // The filer's own wall clock, so date_created reads beside report_date the way the
        // seeded rows do. The audit instant lives on core.actions, which keeps server time.
        $createdAt = $this->timezone->now()->toDateTimeString();
        // Filing is scoped to the member's own board, the same list the picker offers. An edit
        // keeps the full catalogue: a report already filed must stay editable if the assignment
        // is later removed.
        $projects = $this->ownProjectCatalog($employeeId);
        $activities = $this->activityCatalog();
        $earnCodeId = $this->regularEarnCodeId();

        $resolved = [];
        foreach ($groups as $index => $group) {
            $resolved[$index] = $this->resolveNewEntries($group['entries'], $projects, $activities, $earnCodeId);
        }

        // One check per filing, not per line: every group in this request is the same employee.
        $isFlagged = $this->hasActiveWarning($employeeId);

        $insertedIds = $this->connection()->transaction(function () use (
            $employeeId,
            $groups,
            $resolved,
            $lateSubmission,
            $createdAt,
            $isFlagged,
        ): array {
            $ids = [];
            foreach ($groups as $index => $group) {
                foreach ($resolved[$index] as $entry) {
                    $ids[] = $this->insertLine(
                        $employeeId,
                        $group['date'],
                        $entry,
                        $group['remarks'],
                        $lateSubmission,
                        self::NEW_APPROVAL,
                        $createdAt,
                        $isFlagged,
                        self::NEW_FLAG_STATUS,
                    );
                }
            }

            return $ids;
        });

        try {
            foreach ($groups as $group) {
                $id = $group['date'].'-'.$kind;
                $this->ledger->recordAdd(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreRecycleKey::submittedReport($employeeId, $group['date'], $kind),
                    self::CORE_TARGET,
                    [
                        'id' => $id,
                        'kind' => $kind,
                        'submittedOn' => $group['date'],
                        'entries' => $group['entries'],
                        'remarks' => $group['remarks'],
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
                    PortalActivityCopy::addedReport($kind, $group['date']),
                    $id,
                    $request,
                    [
                        'kind' => $kind,
                        'submittedOn' => $group['date'],
                        'remarks' => $group['remarks'],
                        'entries' => $group['entries'],
                    ],
                );
            }
        } catch (Throwable $error) {
            $this->deleteLines($insertedIds);
            throw $error;
        }

        $this->bumpCache();
        $this->recycleBin->bumpCache();

        $wanted = [];
        foreach ($groups as $group) {
            $wanted[] = $group['date'].'-'.$kind;
        }
        $fresh = $this->build($actor, min($dates), max($dates));
        $rows = [];
        foreach ($fresh['data'] as $row) {
            if (in_array($row['id'] ?? null, $wanted, true)) {
                $rows[] = $row;
            }
        }
        if (count($rows) !== count($wanted)) {
            abort(500, 'The report was filed but could not be read back.');
        }

        return [
            'section' => 'reports',
            'resource' => 'submitted',
            'data' => $rows,
        ];
    }

    public function destroy(Employee $actor, string $id, Request $request): void
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

        // Hoisted rather than inlined so the log below can carry the same entries Recycle Bin's
        // own view already reads back from this snapshot.
        $snapshot = $this->recycleSnapshot($actor, $id, $date, $kind, $existing);
        $written = $this->ledger->recycleDeleted(
            CoreLedger::PRODUCT_PORTAL,
            CoreRecycleKey::submittedReport($employeeId, $date, $kind),
            self::CORE_TARGET,
            $id,
            $snapshot,
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

        $this->audit->record(
            $actor,
            PortalLogAction::DELETE,
            self::CORE_RESOURCE,
            PortalActivityCopy::deletedReport($kind, $date),
            $id,
            $request,
            [
                'kind' => $kind,
                'submittedOn' => $date,
                'remarks' => $snapshot['report']['reason'] ?? null,
                'entries' => $snapshot['report']['entries'] ?? [],
            ],
        );

        $this->bumpCache();
        $this->recycleBin->bumpCache();
    }

    /**
     * Put a recycled submitted report back. Logs action_type add and drops the recycle
     * row. The original delete action stays — restore is an add, not an erase.
     */
    public function restoreFromBin(Employee $actor, Recycle $row, Request $request): void
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
            $this->audit->record(
                $actor,
                PortalLogAction::INSERT,
                self::CORE_RESOURCE,
                PortalActivityCopy::restoredReport($kind, $date),
                (string) $row->record_id,
                $request,
                [
                    'kind' => $kind,
                    'submittedOn' => $date,
                    'remarks' => $payload['report']['reason'] ?? null,
                    'entries' => $payload['report']['entries'] ?? [],
                    'restored' => true,
                ],
            );
            $owner = Employee::query()->find($ownerId);
            if ($owner instanceof Employee) {
                $this->audit->notifyIfOther(
                    $owner,
                    $actor,
                    PortalNotificationType::BIN_RESTORED,
                    'Report restored',
                    PortalActivityCopy::notifyReportRestored($kind, $date, PortalActivityCopy::displayName($actor)),
                    PortalShellPath::SUBMITTED_REPORTS,
                    'Open reports',
                    ['recordId' => (string) $row->record_id],
                );
            }
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

        $to = $toRaw === '' ? $this->timezone->today() : $this->parseDate($toRaw);
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

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function parseDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            // Built in the member's zone, so comparing it with today compares two wall clocks
            // rather than an instant against a day boundary somewhere else.
            $date = Carbon::createFromFormat('Y-m-d', $value, $this->timezone->zone());
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
     * `blocked` is the subset of `leave` a new report may not be filed against: a rejected leave
     * request means the member was told to work that day, so the day is theirs to report on.
     *
     * `overtime` is the day an overtime request already claims, on the same refused-means-free
     * rule: an overtime request that was turned down leaves the day open to ask again.
     *
     * @return array{leave: list<string>, offset: list<string>, blocked: list<string>, overtime: list<string>}
     */
    private function occupancy(Employee $actor, string $from, string $to): array
    {
        $name = PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
        if ($name === 'Member') {
            return ['leave' => [], 'offset' => [], 'blocked' => [], 'overtime' => []];
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
        $blocked = [];
        $overtime = [];
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
                    if (! PortalSubmittedReportPresenter::isRefused($row->status ?? null)) {
                        $blocked[] = $date;
                    }
                }

                continue;
            }
            // A refused overtime request is the day back on offer, same rule leave follows.
            if ($kind === PortalSubmittedReportPresenter::OCCUPANCY_OVERTIME) {
                $date = $this->calendarDate($row->request_date ?? null);
                if ($date !== null && ! PortalSubmittedReportPresenter::isRefused($row->status ?? null)) {
                    $overtime[] = $date;
                }

                continue;
            }
            if ($kind !== PortalSubmittedReportPresenter::OCCUPANCY_OFFSET) {
                continue;
            }
            // A refused offset is both days back on offer, the same rule leave and overtime follow.
            if (PortalSubmittedReportPresenter::isRefused($row->status ?? null)) {
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
            'blocked' => PortalSubmittedReportPresenter::uniqueDates($blocked),
            'overtime' => PortalSubmittedReportPresenter::uniqueDates($overtime),
        ];
    }

    /**
     * One grouped read over (employee_id, report_date): two columns, no label joins, so the
     * picker can ask for two years of days without paying for two years of entries.
     *
     * @return list<array{date: string, kind: string}>
     */
    private function filedDays(int $employeeId, string $from, string $to): array
    {
        $rows = UserReport::query()
            ->toBase()
            ->select(['user_reports.report_date', 'user_reports.late_submission'])
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->whereNotNull('user_reports.report_date')
            ->whereBetween('user_reports.report_date', [$from, $to])
            ->groupBy('user_reports.report_date', 'user_reports.late_submission')
            ->orderBy('user_reports.report_date')
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $date = $this->calendarDate($row->report_date ?? null);
            if ($date === null) {
                continue;
            }
            $kind = PortalSubmittedReportPresenter::kind($row->late_submission ?? null);
            $days[$date.'-'.$kind] = ['date' => $date, 'kind' => $kind];
        }

        return array_values($days);
    }

    private function validatedKind(Request $request): string
    {
        $kind = strtolower(trim((string) $request->input('kind', '')));
        if ($kind !== PortalSubmittedReportPresenter::KIND_DAILY && $kind !== PortalSubmittedReportPresenter::KIND_LATE) {
            abort(422, 'A report is either daily or late.');
        }

        return $kind;
    }

    /**
     * @return list<array{
     *     date: string,
     *     remarks: ?string,
     *     entries: list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>
     * }>
     */
    private function validatedGroups(Request $request, string $kind): array
    {
        $raw = $request->input('reports');
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
            $date = $this->parseDate(trim((string) ($item['reportDate'] ?? '')))->toDateString();
            $this->assertFilableDate($date, $kind);
            if (in_array($date, $seen, true)) {
                abort(422, 'That filing lists the same day twice.');
            }
            $seen[] = $date;
            $groups[] = [
                'date' => $date,
                'remarks' => $this->validatedRemarksValue($item['remarks'] ?? null),
                'entries' => $this->validatedEntryList($item['entries'] ?? null),
            ];
        }

        return $groups;
    }

    /**
     * Daily files against the same seven-day strip an edit may touch. Late is any previous day:
     * a missed daily filing is filed here, and the two forms still cannot double a day because
     * assertDatesAreFree refuses a second report on one date.
     */
    private function assertFilableDate(string $date, string $kind): void
    {
        if ($kind === PortalSubmittedReportPresenter::KIND_DAILY) {
            $this->assertMutableDate($date);

            return;
        }

        $day = $this->parseDate($date);
        $today = $this->timezone->today();
        if ($day->gte($today)) {
            abort(422, 'A late report is for a previous day.');
        }
        if ($day->lt($today->copy()->subMonths(self::MAX_RANGE_MONTHS)->startOfMonth())) {
            abort(422, 'A late report cannot reach further back than two years.');
        }
    }

    /**
     * A day holds one report, whichever kind it is: two would double the hours it carries.
     *
     * @param  list<string>  $dates
     */
    private function assertDatesAreFree(int $employeeId, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $taken = UserReport::query()
            ->toBase()
            ->select(['user_reports.report_date'])
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->whereIn('user_reports.report_date', $dates)
            ->limit(1)
            ->get()
            ->first();

        if ($taken === null) {
            return;
        }

        $day = $this->calendarDate($taken->report_date ?? null) ?? 'that day';
        abort(422, 'A report already exists for '.$day.'. Edit it from Submitted Reports instead.');
    }

    /**
     * Leave that was filed and not refused means the member was not at work, so there is nothing
     * to report. A rejected request is the opposite: they were told to work that day.
     *
     * @param  list<string>  $dates
     */
    private function assertDatesAreWorked(Employee $actor, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $blocked = $this->occupancy($actor, min($dates), max($dates))['blocked'];
        foreach ($dates as $date) {
            if (in_array($date, $blocked, true)) {
                abort(422, 'You have leave filed for '.$date.', so there is no report to file.');
            }
        }
    }

    /**
     * The create twin of resolveEntries: no existing lines to inherit an earn code from, and the
     * two catalogs are read once for the whole batch rather than once per day.
     *
     * @param  list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>  $entries
     * @param  array<string, int>  $projects
     * @param  array<string, int>  $activities
     * @return list<array{projectId: int, activityCodeId: int, earnCodeId: ?int, hoursRendered: float, elementChange: float}>
     */
    private function resolveNewEntries(array $entries, array $projects, array $activities, ?int $earnCodeId): array
    {
        $resolved = [];
        foreach ($entries as $entry) {
            $projectId = $projects[$this->lookupKey($entry['projectLabel'])] ?? null;
            $activityId = $activities[$this->lookupKey($entry['activityLabel'])] ?? null;
            // Names the label rather than the rule: a picker offering something the database does
            // not have is the usual cause, and the generic wording hides which half was wrong.
            if ($projectId === null) {
                abort(422, '"'.$entry['projectLabel'].'" is not one of your projects.');
            }
            if ($activityId === null) {
                abort(422, 'No activity matches "'.$entry['activityLabel'].'".');
            }
            $resolved[] = [
                'projectId' => $projectId,
                'activityCodeId' => $activityId,
                'earnCodeId' => $earnCodeId,
                'hoursRendered' => $entry['hoursRendered'],
                'elementChange' => $entry['elementChange'],
            ];
        }

        return $resolved;
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
        $today = $this->timezone->today();
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
                'employees_user_reports.is_flag',
                'employees_user_reports.flag_status',
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
        return $this->validatedEntryList($request->input('entries'));
    }

    /**
     * @return list<array{projectLabel: string, activityLabel: string, hoursRendered: float, elementChange: float}>
     */
    private function validatedEntryList(mixed $raw): array
    {
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

        return $this->validatedRemarksValue($request->input('remarks'));
    }

    private function validatedRemarksValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            abort(422, 'Remarks must be text.');
        }
        $text = trim((string) $value);
        if (strlen($text) > 500) {
            abort(422, 'Remarks cannot exceed 500 characters.');
        }

        return $text === '' ? null : $text;
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
     * The projects this member is assigned to, keyed the same way projectCatalog() keys the whole
     * table. One read over the (employee_id, project_id) primary key.
     *
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
        bool $isFlagged,
        string $flagStatus,
    ): int {
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
            'is_flag' => $isFlagged,
            'flag_status' => $flagStatus,
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

        return $id;
    }

    // A warning stays on file after it lapses, so only an Active row counts against a new filing.
    private function hasActiveWarning(int $employeeId): bool
    {
        return $this->connection()
            ->table('employees_warnings')
            ->join('warnings', 'warnings.id', '=', 'employees_warnings.warning_id')
            ->where('employees_warnings.employee_id', $employeeId)
            ->where('warnings.status', 'Active')
            ->exists();
    }

    private function lookupKey(string $label): string
    {
        return strtolower(trim($label));
    }

    private function managedSubject(Employee $actor, Request $request): Employee
    {
        $raw = trim((string) $request->query('employee_id', ''));
        if ($raw === '') {
            return $actor;
        }
        if (preg_match('/^\d{1,11}$/', $raw) !== 1) {
            abort(404, 'That member was not found.');
        }

        $subject = Employee::query()->find((int) $raw);
        if (! $subject instanceof Employee) {
            abort(404, 'That member was not found.');
        }

        return $subject;
    }

    /**
     * Active members the picker can open. Skinny: an id and a display name, nothing else.
     *
     * @return list<array{value: string, label: string}>
     */
    private function roster(): array
    {
        $rows = Employee::query()
            ->select(['id', 'first_name', 'last_name'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        $employees = [];
        foreach ($rows as $row) {
            $employees[] = [
                'value' => (string) $row->getKey(),
                'label' => PortalSubmittedReportPresenter::memberName(
                    is_string($row->first_name) ? $row->first_name : null,
                    is_string($row->last_name) ? $row->last_name : null,
                ),
            ];
        }

        return $employees;
    }
}
