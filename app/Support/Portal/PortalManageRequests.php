<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\UserReport;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Manage / Requests: every member's leave, overtime, offset, claims and late reports for
 * Admin/Executive.
 *
 * Ownership on `requests` is the composed name Airtable stored. Claims join through
 * `employees_reimbursements` when that row exists, and fall back to `employee_name_input`.
 */
final class PortalManageRequests
{
    public const CACHE_TTL_SECONDS = 30;

    private const MAX_RANGE_MONTHS = 24;

    private const MAX_AHEAD_MONTHS = 12;

    private const CACHE_VERSION_KEY = 'portal:manage:requests:version';

    public function __construct(
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
        private readonly PortalLeaveRequests $leave,
        private readonly PortalOvertimeRequests $overtime,
        private readonly PortalOffsetRequests $offset,
        private readonly PortalReimbursementRequests $reimbursements,
        private readonly PortalBudgetEnvelopes $budget,
        private readonly PortalSubmittedReports $reports,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     leave: list<array<string, mixed>>,
     *     overtime: list<array<string, mixed>>,
     *     offset: list<array<string, mixed>>,
     *     reimbursements: list<array<string, mixed>>,
     *     lateReports: list<array<string, mixed>>,
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Request $request): array
    {
        [$from, $to] = $this->dateRange($request);
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:manage:requests:'.$version.':'.$this->reports->cacheVersion().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($from, $to): array {
            [$data, $leave, $overtime, $offset] = $this->requestLists($from, $to);

            return [
                'section' => 'manage',
                'resource' => 'requests',
                'data' => $data,
                'leave' => $leave,
                'overtime' => $overtime,
                'offset' => $offset,
                'reimbursements' => $this->claims($from, $to),
                'lateReports' => $this->reports->lateQueue($from, $to),
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    /**
     * @return array{section: string, resource: string, saved: int}
     */
    public function decide(Employee $actor, Request $request): array
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:50'],
            'changes.*.id' => ['required', 'string', 'max:32'],
            'changes.*.kind' => ['required', 'in:leave,overtime,holiday-work,offset,reimbursement,late'],
            'changes.*.status' => ['required', 'in:pending,approved,rejected'],
            'changes.*.approverRemarks' => ['nullable', 'string', 'max:2000'],
            // Reimbursement approvals only: the envelope the claim is charged to, and the approver's
            // explicit go-ahead when the claim is larger than what that envelope has left.
            'changes.*.envelopeId' => ['nullable', 'integer', 'min:1'],
            'changes.*.allowOverspend' => ['nullable', 'boolean'],
        ]);

        // Charges made or removed in this batch, logged once the batch has actually committed.
        $ledger = [];
        $saved = $this->budget->withinLedger(function () use ($validated, $actor, $request, &$ledger): int {
            return $this->connection()->transaction(function () use ($validated, $actor, $request, &$ledger): int {
                $count = 0;
                foreach ($validated['changes'] as $change) {
                    $applied = $this->applyChange($actor, $change);
                    if (($applied['ledger'] ?? null) !== null) {
                        $ledger[] = $applied['ledger'];
                    }
                    $this->notifyOwnerOfDecision($actor, $applied, $request);
                    $count++;
                }

                return $count;
            });
        });

        foreach ($ledger as $entry) {
            $this->audit->record(
                $actor,
                $entry['action'],
                'manage.budget-envelopes',
                $entry['message'],
                $entry['recordId'],
                $request,
                $entry['payload'],
            );
        }

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'manage.requests',
            PortalActivityCopy::reviewedRequestStatuses($saved),
            null,
            $request,
            ['changes' => $validated['changes']],
        );

        $this->bumpCache();
        $this->leave->bumpCache();
        $this->overtime->bumpCache();
        $this->offset->bumpCache();
        $this->reimbursements->bumpCache();
        $this->reports->bumpCache();

        return [
            'section' => 'manage',
            'resource' => 'requests',
            'saved' => $saved,
        ];
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @param  array{id: string, kind: string, status: string, approverRemarks?: string|null, envelopeId?: int|null, allowOverspend?: bool|null}  $change
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string, ledger?: array<string, mixed>|null}
     */
    private function applyChange(Employee $actor, array $change): array
    {
        $status = $this->storedStatus($change['status']);
        $remarks = $this->text($change['approverRemarks'] ?? null);

        // A late report is named by its member and day rather than by a row, so it is the one kind
        // whose id is not a number.
        if ($change['kind'] === 'late') {
            $decided = $this->reports->decideLate($change['id'], $status, $remarks);

            return [
                'owner' => $decided['owner'],
                'kind' => 'late',
                'date' => $decided['date'],
                'recordId' => $change['id'],
                'status' => $change['status'],
            ];
        }

        $id = (int) $change['id'];
        if ($id < 1 || (string) $id !== $change['id']) {
            abort(422, 'Each change needs a numeric id.');
        }

        if ($change['kind'] === 'reimbursement') {
            $envelopeId = $change['envelopeId'] ?? null;

            return $this->applyClaim(
                $actor,
                $id,
                $status,
                $remarks,
                $change['status'],
                $envelopeId === null ? null : (int) $envelopeId,
                (bool) ($change['allowOverspend'] ?? false),
            );
        }

        return $this->applyQueuedRequest($id, $change['kind'], $status, $remarks, $change['status']);
    }

    /**
     * @param  array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}  $applied
     */
    private function notifyOwnerOfDecision(Employee $actor, array $applied, Request $request): void
    {
        $owner = $applied['owner'];
        if (! $owner instanceof Employee) {
            return;
        }

        $verb = match ($applied['status']) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default => 'set back to pending',
        };
        $kind = $this->kindLabel($applied['kind']);
        $resource = $this->resourceForKind($applied['kind']);
        $subject = $kind.' for '.PortalActivityCopy::date($applied['date']);
        $noun = $applied['kind'] === 'late' ? 'Late report' : 'Request';

        $this->audit->record(
            $owner,
            PortalLogAction::PATCH,
            $resource,
            PortalActivityCopy::YOUR.' '.$subject.' has been '.$verb.' by '.PortalActivityCopy::displayName($actor),
            $applied['recordId'],
            $request,
            [
                'kind' => $applied['kind'],
                'requestedFor' => $applied['date'],
                'status' => $applied['status'],
                'decidedBy' => PortalActivityCopy::displayName($actor),
            ],
        );
        $this->audit->notifyIfOther(
            $owner,
            $actor,
            $resource.'.'.$applied['status'],
            match ($applied['status']) {
                'approved' => $noun.' approved',
                'rejected' => $noun.' rejected',
                default => $noun.' updated',
            },
            PortalActivityCopy::notifyDecided($kind, $applied['date'], $verb, PortalActivityCopy::displayName($actor)),
            PortalShellPath::USER_REQUESTS,
            'Open requests',
            ['recordId' => $applied['recordId']],
        );
    }

    /**
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}
     */
    private function applyQueuedRequest(int $id, string $kind, string $status, ?string $remarks, string $apiStatus): array
    {
        $row = $this->connection()
            ->table('requests')
            ->select([
                'id',
                'name',
                'request_date',
                'reason',
                'status',
                'category',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->where('id', $id)
            ->first();
        if ($row === null) {
            abort(404, 'That request is not on the queue.');
        }

        $queueKind = $this->queueKind($row);
        if ($queueKind !== $kind) {
            abort(422, 'That request is not that kind.');
        }

        $this->connection()->table('requests')->where('id', $id)->update([
            'status' => $status,
            'approver_remarks' => $remarks,
        ]);

        $date = $this->calendarDate($row->request_date ?? null)
            ?? $this->calendarDate($row->original_work_day ?? null)
            ?? '';

        return [
            'owner' => $this->employeeNamed($this->text($row->name ?? null)),
            'kind' => $kind,
            'date' => $date,
            'recordId' => (string) $id,
            'status' => $apiStatus,
        ];
    }

    /**
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string, ledger: array<string, mixed>|null}
     */
    private function applyClaim(
        Employee $actor,
        int $id,
        string $status,
        ?string $remarks,
        string $apiStatus,
        ?int $envelopeId,
        bool $allowOverspend,
    ): array {
        $seed = $this->connection()
            ->table('reimbursements')
            ->select(['id', 'date_created', 'employee_name_input', 'reimb_date', 'status'])
            ->where('id', $id)
            ->first();
        if ($seed === null) {
            abort(404, 'That reimbursement is not on the queue.');
        }

        $name = $this->text($seed->employee_name_input ?? null);
        $claim = function () use ($seed, $name) {
            $query = $this->connection()
                ->table('reimbursements')
                ->where('date_created', $seed->date_created);
            if ($name === null) {
                $query->where(function ($inner): void {
                    $inner->whereNull('employee_name_input')->orWhere('employee_name_input', '');
                });
            } else {
                $query->where('employee_name_input', $seed->employee_name_input);
            }

            return $query;
        };

        // Read before the update: the charge is the claim as it stood when the approver decided it.
        $rows = $claim()->get(['id', 'item', 'cost', 'qty']);
        if ($rows->isEmpty()) {
            abort(404, 'That reimbursement is not on the queue.');
        }

        // Existence is settled by the read above, not by the update's row count: MySQL counts only
        // rows it changed, so a second item of an already-decided claim updated nothing and used to
        // come back as a 404 for a claim that was plainly there.
        $claim()->update([
            'status' => $status,
            'approver_remarks' => $remarks,
        ]);

        $owner = $this->claimOwner($id, $name);

        $filedOn = $this->calendarDate($seed->reimb_date ?? null) ?? '';
        $memberName = $owner instanceof Employee
            ? PortalActivityCopy::displayName($owner)
            : ($name ?? 'Member');

        return [
            'owner' => $owner,
            'kind' => 'reimbursement',
            'date' => $filedOn,
            'recordId' => (string) $id,
            'status' => $apiStatus,
            'ledger' => $this->settleClaimCharge(
                $actor,
                $rows->all(),
                $apiStatus,
                $envelopeId,
                $allowOverspend,
                $memberName,
                $filedOn,
                $owner === null ? null : $this->text($owner->department ?? null),
            ),
        ];
    }

    /**
     * The department of whoever filed a claim, found by the claim's id -- its lowest row id, the same
     * one its charge carries. Null when the claimant cannot be found or has no department.
     */
    public function claimantDepartment(int $claimId): ?string
    {
        $seed = $this->connection()
            ->table('reimbursements')
            ->where('id', $claimId)
            ->first(['id', 'employee_name_input']);
        if ($seed === null) {
            return null;
        }
        $owner = $this->claimOwner($claimId, $this->text($seed->employee_name_input ?? null));

        return $owner === null ? null : $this->text($owner->department ?? null);
    }

    // The employee a reimbursement row is linked to, or failing a link, the one its name belongs to.
    private function claimOwner(int $id, ?string $name): ?Employee
    {
        $linkedId = $this->connection()
            ->table('employees_reimbursements')
            ->where('reimbursement_id', $id)
            ->value('employee_id');
        $owner = is_numeric($linkedId) ? Employee::query()->find((int) $linkedId) : null;

        return $owner instanceof Employee ? $owner : $this->employeeNamed($name);
    }

    /**
     * Keeps a claim's envelope charge in step with its decision: charged while approved, removed the
     * moment it stops being approved.
     *
     * The backend decides a whole claim at once, so the charge is the claim's full total however many
     * of its items the approver staged. Two items of one claim in one save therefore charge once.
     *
     * @param  list<object>  $rows
     * @return array{action: string, message: string, recordId: string, payload: array<string, mixed>}|null
     */
    private function settleClaimCharge(
        Employee $actor,
        array $rows,
        string $apiStatus,
        ?int $envelopeId,
        bool $allowOverspend,
        string $memberName,
        string $filedOn,
        ?string $department,
    ): ?array {
        if (! $this->budget->isAvailable() || $rows === []) {
            return null;
        }

        // The same identity the queue gives the claim, so the portal and the ledger name one thing.
        $claimId = (int) min(array_map(static fn (object $row): int => (int) $row->id, $rows));
        $existing = $this->budget->chargeFor($claimId);

        if ($apiStatus !== 'approved') {
            $released = $existing === null ? null : $this->budget->release($claimId);
            if ($released === null) {
                return null;
            }

            return [
                'action' => PortalLogAction::DELETE,
                'message' => PortalActivityCopy::releasedReimbursementCharge(
                    $released['envelopeName'],
                    $this->peso($released['amount']),
                ),
                'recordId' => (string) $released['transactionId'],
                'payload' => [
                    'claimId' => (string) $claimId,
                    'envelopeId' => (string) $released['envelopeId'],
                    'envelope' => $released['envelopeName'],
                    'amount' => $released['amount'],
                ],
            ];
        }

        if ($existing !== null) {
            if ($envelopeId !== null && $envelopeId !== $existing['envelopeId']) {
                abort(422, 'This claim is already charged to '.$existing['envelopeName'].'. One claim is charged to one envelope.');
            }

            return null;
        }

        if ($envelopeId === null) {
            abort(422, 'Choose a budget envelope to charge this reimbursement to.');
        }

        $amountCents = 0;
        $items = [];
        foreach ($rows as $row) {
            $amountCents += (int) round(((float) ($row->cost ?? 0)) * 100) * $this->quantity($row->qty ?? null);
            $items[] = $this->text($row->item ?? null) ?? 'Item';
        }

        $charge = $this->budget->charge($actor, [
            'id' => $claimId,
            'memberName' => $memberName,
            'filedOn' => $filedOn,
            'amountCents' => $amountCents,
            'items' => $items,
            'department' => $department,
        ], $envelopeId, $allowOverspend);

        return [
            'action' => PortalLogAction::POST,
            'message' => PortalActivityCopy::chargedReimbursement(
                $memberName,
                $charge['envelopeName'],
                $this->peso($charge['amount']),
            ),
            'recordId' => (string) $charge['transactionId'],
            'payload' => [
                'claimId' => (string) $claimId,
                'envelopeId' => (string) $charge['envelopeId'],
                'envelope' => $charge['envelopeName'],
                'amount' => $charge['amount'],
                'remainingAfter' => $charge['remainingAfter'],
                'overspent' => $charge['overspent'],
                'accountId' => $charge['accountId'],
                'account' => $charge['accountName'],
                'team' => $charge['team'],
            ],
        ];
    }

    private function peso(float $amount): string
    {
        return ($amount < 0 ? '-' : '').'₱'.number_format(abs($amount), 2);
    }

    private function employeeNamed(?string $name): ?Employee
    {
        $key = strtolower(trim((string) $name));
        if ($key === '') {
            return null;
        }

        return Employee::query()
            ->whereRaw(
                "LOWER(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))) = ?",
                [$key],
            )
            ->first();
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'leave' => 'leave request',
            'overtime' => 'overtime request',
            'holiday-work' => 'holiday work request',
            'offset' => 'offset request',
            'late' => 'late report',
            default => 'reimbursement request',
        };
    }

    private function resourceForKind(string $kind): string
    {
        return match ($kind) {
            'leave' => 'requests.leave',
            'overtime', 'holiday-work' => 'requests.overtime',
            'offset' => 'requests.offset',
            'late' => 'reports.submitted',
            default => 'requests.reimbursement',
        };
    }

    private function storedStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Pending',
        };
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
     */
    private function requestLists(string $from, string $to): array
    {
        $rows = $this->connection()
            ->table('requests')
            ->select([
                'id',
                'name',
                'request_date',
                'date_created',
                'reason',
                'status',
                'approver_remarks',
                'category',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('request_date', [$from, $to])
                    ->orWhereBetween('original_work_day', [$from, $to])
                    ->orWhereBetween('offset_work_day', [$from, $to]);
            })
            ->orderByDesc('date_created')
            ->orderByDesc('id')
            ->get();

        $data = [];
        $leave = [];
        $overtime = [];
        $offset = [];
        foreach ($rows as $row) {
            $presented = $this->presentRequest($row);
            if ($presented === null) {
                continue;
            }
            $data[] = $presented['managed'];
            if ($presented['leave'] !== null) {
                $leave[] = $presented['leave'];
            }
            if ($presented['overtime'] !== null) {
                $overtime[] = $presented['overtime'];
            }
            if ($presented['offset'] !== null) {
                $offset[] = $presented['offset'];
            }
        }

        return [$data, $leave, $overtime, $offset];
    }

    /**
     * @return array{
     *     managed: array<string, mixed>,
     *     leave: ?array<string, mixed>,
     *     overtime: ?array<string, mixed>,
     *     offset: ?array<string, mixed>
     * }|null
     */
    private function presentRequest(object $row): ?array
    {
        $memberName = $this->text($row->name ?? null);
        if ($memberName === null) {
            return null;
        }
        $queueKind = $this->queueKind($row);
        if ($queueKind === null) {
            return null;
        }
        $requestedOn = $queueKind === 'offset'
            ? ($this->calendarDate($row->original_work_day ?? null)
                ?? $this->calendarDate($row->request_date ?? null))
            : $this->calendarDate($row->request_date ?? null);
        if ($requestedOn === null) {
            return null;
        }
        $createdOn = $this->calendarDate($row->date_created ?? null) ?? $requestedOn;
        $status = PortalSubmittedReportPresenter::requestStatus($row->status ?? null);
        $parsed = PortalSubmittedReportPresenter::splitComposedReason($this->text($row->reason ?? null));
        $hours = $this->hours($row->no_of_hours ?? null);
        $first = $parsed['entries'][0] ?? null;
        $id = (string) (int) $row->id;
        $approverRemarks = $this->text($row->approver_remarks ?? null);
        $remarks = $queueKind === 'leave' ? $this->text($row->reason ?? null) : $parsed['remarks'];
        $dayOffOn = $this->calendarDate($row->offset_work_day ?? null);
        $workOn = $this->calendarDate($row->original_work_day ?? null);

        $managed = [
            'id' => $id,
            'createdOn' => $createdOn,
            'requestedOn' => $requestedOn,
            'kind' => $queueKind,
            'memberName' => $memberName,
            'offsetFromOn' => $workOn,
            'offsetToOn' => $dayOffOn,
            'projectLabel' => $parsed['projectLabel'],
            'activityLabel' => is_array($first) ? ($first['activityLabel'] ?? null) : null,
            'earnCodeLabel' => $queueKind === 'leave' ? ($this->text($row->category ?? null) ?? 'Leave') : null,
            'hours' => $hours,
            'elementChange' => is_array($first) ? ($first['elementChange'] ?? null) : null,
            'remarks' => $remarks,
            'status' => $status,
            'approverRemarks' => $approverRemarks,
        ];

        $leave = null;
        $overtime = null;
        $offset = null;
        if ($queueKind === 'leave') {
            $leave = [
                'id' => $id,
                'createdOn' => $createdOn,
                'requestedOn' => $requestedOn,
                'leaveTypeLabel' => $this->text($row->category ?? null) ?? 'Leave',
                'remarks' => $remarks,
                'status' => $status,
                'approverRemarks' => $approverRemarks,
                'memberName' => $memberName,
            ];
        }
        if ($queueKind === 'overtime' || $queueKind === 'holiday-work') {
            $overtime = [
                'id' => $id,
                'createdOn' => $createdOn,
                'requestedOn' => $requestedOn,
                'hours' => $hours ?? 0,
                'remarks' => $parsed['remarks'],
                'status' => $status,
                'approverRemarks' => $approverRemarks,
                'projectLabel' => $parsed['projectLabel'],
                'memberName' => $memberName,
                'entries' => $parsed['entries'],
            ];
        }
        if ($queueKind === 'offset' && $dayOffOn !== null) {
            $offset = [
                'id' => $id,
                'createdOn' => $createdOn,
                'requestedOn' => $requestedOn,
                'dayOffOn' => $dayOffOn,
                'hours' => $hours ?? 0,
                'remarks' => $parsed['remarks'],
                'status' => $status,
                'approverRemarks' => $approverRemarks,
                'projectLabel' => $parsed['projectLabel'],
                'memberName' => $memberName,
                'entries' => $parsed['entries'],
            ];
        }

        return [
            'managed' => $managed,
            'leave' => $leave,
            'overtime' => $overtime,
            'offset' => $offset,
        ];
    }

    private function queueKind(object $row): ?string
    {
        $type = strtolower(trim((string) ($row->type ?? '')));
        if (in_array($type, ['holiday-work', 'holiday work'], true)) {
            return 'holiday-work';
        }
        $occupancy = PortalSubmittedReportPresenter::occupancy(
            $row->type ?? null,
            $row->no_of_hours ?? null,
            $this->calendarDate($row->original_work_day ?? null),
            $this->calendarDate($row->offset_work_day ?? null),
        );

        return match ($occupancy) {
            PortalSubmittedReportPresenter::OCCUPANCY_LEAVE => 'leave',
            PortalSubmittedReportPresenter::OCCUPANCY_OVERTIME => 'overtime',
            PortalSubmittedReportPresenter::OCCUPANCY_OFFSET => 'offset',
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claims(string $from, string $to): array
    {
        $rows = $this->connection()
            ->table('reimbursements')
            ->leftJoin(
                'employees_reimbursements',
                'employees_reimbursements.reimbursement_id',
                '=',
                'reimbursements.id',
            )
            ->leftJoin('employees', 'employees.id', '=', 'employees_reimbursements.employee_id')
            ->select([
                'reimbursements.id',
                'reimbursements.reimb_date',
                'reimbursements.item',
                'reimbursements.cost',
                'reimbursements.qty',
                'reimbursements.purpose',
                'reimbursements.team',
                'reimbursements.status',
                'reimbursements.approver_remarks',
                'reimbursements.date_created',
                'reimbursements.employee_name_input',
                'employees_reimbursements.employee_id',
                'employees.id_no',
                'employees.first_name',
                'employees.last_name',
            ])
            ->whereNotNull('reimbursements.reimb_date')
            ->whereBetween('reimbursements.reimb_date', [$from, $to])
            ->orderByDesc('reimbursements.reimb_date')
            ->orderByDesc('reimbursements.id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->claimKey($row)][] = $row;
        }

        $data = [];
        foreach ($groups as $group) {
            $claim = $this->presentClaim($group);
            if ($claim !== null) {
                $data[] = $claim;
            }
        }

        return $data;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, mixed>|null
     */
    private function presentClaim(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        $first = $rows[0];
        $submittedOn = $this->calendarDate($first->reimb_date ?? null);
        if ($submittedOn === null) {
            return null;
        }
        $ids = [];
        $items = [];
        $approverRemarks = null;
        foreach ($rows as $row) {
            $itemId = (int) $row->id;
            $ids[] = $itemId;
            $items[] = [
                'id' => (string) $itemId,
                'label' => $this->text($row->item ?? null) ?? 'Item',
                'cost' => round((float) ($row->cost ?? 0), 2),
                'quantity' => $this->quantity($row->qty ?? null),
                'teamLabel' => $this->text($row->team ?? null),
                'purpose' => $this->text($row->purpose ?? null),
                'receiptName' => null,
                'receiptUrl' => null,
                'receiptMime' => null,
                'receiptThumbUrl' => null,
            ];
            if ($approverRemarks === null) {
                $approverRemarks = $this->text($row->approver_remarks ?? null);
            }
        }
        $memberName = PortalSubmittedReportPresenter::memberName(
            is_string($first->first_name ?? null) ? $first->first_name : null,
            is_string($first->last_name ?? null) ? $first->last_name : null,
        );
        if ($memberName === 'Member') {
            $memberName = $this->text($first->employee_name_input ?? null) ?? 'Member';
        }

        return [
            'id' => (string) min($ids),
            'referenceCode' => PortalSubmittedReportPresenter::referenceCode(
                $first->id_no ?? null,
                $first->employee_id ?? min($ids),
            ),
            'memberName' => $memberName,
            'submittedOn' => $submittedOn,
            'status' => $this->claimStatus($first->status ?? null),
            'approverRemarks' => $approverRemarks,
            'items' => $items,
        ];
    }

    private function claimKey(object $row): string
    {
        $employeeId = (int) ($row->employee_id ?? 0);
        $name = strtolower(trim((string) ($row->employee_name_input ?? '')));

        return $employeeId.'|'.$name.'|'.$this->createdStamp($row->date_created ?? null).'|'.strtolower(trim((string) ($row->status ?? '')));
    }

    private function createdStamp(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '' : $text;
    }

    private function claimStatus(mixed $status): string
    {
        $value = strtolower(trim((string) $status));
        if (in_array($value, ['completed', 'complete', 'paid', 'done'], true)) {
            return 'approved';
        }

        return PortalSubmittedReportPresenter::requestStatus($status);
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
            : $this->parseDate($toRaw);
        $floor = $to->copy()->subMonths(self::MAX_RANGE_MONTHS + self::MAX_AHEAD_MONTHS)->startOfMonth();
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

    private function hours(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function quantity(mixed $value): int
    {
        $quantity = (int) round((float) ($value ?? 1));

        return $quantity > 0 ? $quantity : 1;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function connection(): Connection
    {
        return UserReport::query()->getConnection();
    }
}
