<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\UserReport;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Manage / Requests: other people's leave, overtime, offset, claims, flagged reports and late
 * reports, each kind behind its own rule (manage.requests.<kind>) and only as far as that rule
 * reaches. Deciding a kind needs its Approve or reject rule, and attaching payout proof its own.
 *
 * Ownership on `requests` is the composed name Airtable stored. Claims join through
 * `employees_reimbursements` when that row exists, and fall back to `employee_name_input`.
 */
final class PortalManageRequests
{
    public const CACHE_TTL_SECONDS = 30;

    private const MAX_RANGE_MONTHS = 24;

    private const MAX_AHEAD_MONTHS = 12;

    private const CACHE_VERSION_KEY = PortalCache::DATA_VERSION_KEY;

    // The rule behind each kind on the queue; deciding one is that rule's ".decide".
    private const VIEW_RULES = [
        'leave' => 'manage.requests.leave',
        'overtime' => 'manage.requests.overtime',
        'holiday-work' => 'manage.requests.overtime',
        'offset' => 'manage.requests.offset',
        'reimbursement' => 'manage.requests.reimbursement',
        'flag' => 'manage.requests.flagged',
        'late' => 'manage.requests.late',
    ];

    public function __construct(
        private readonly CoreLedger $ledger,
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
     *     flaggedReports: list<array<string, mixed>>,
     *     lateReports: list<array<string, mixed>>,
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Request $request): array
    {
        [$from, $to] = $this->dateRange($request);
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }
        $reaches = $this->reaches($actor, $request);
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        // Everything the reach depends on is in the key, so one person's queue is never served to another.
        $scope = md5((string) json_encode([$reaches, PortalPreview::department($actor->department), (int) $actor->getKey()]));
        $key = 'portal:manage:requests:'.$version.':'.$this->reports->cacheVersion().':'.$from.':'.$to.':'.$scope;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($from, $to, $actor, $reaches): array {
            [$data, $leave, $overtime, $offset] = $this->requestLists($from, $to);
            $employees = $this->employeesByName();
            $of = static fn (string $kind): callable => static fn (): ?string => $reaches[$kind];

            return [
                'section' => 'manage',
                'resource' => 'requests',
                'data' => $this->scoped($data, $actor, $employees, static fn (array $row): ?string => $reaches[(string) $row['kind']] ?? null),
                'leave' => $this->scoped($leave, $actor, $employees, $of('leave')),
                'overtime' => $this->scoped($overtime, $actor, $employees, $of('overtime')),
                'offset' => $this->scoped($offset, $actor, $employees, $of('offset')),
                'reimbursements' => $this->scoped($this->claims($from, $to), $actor, $employees, $of('reimbursement')),
                'flaggedReports' => $this->scoped($this->reports->flaggedReports($from, $to), $actor, $employees, $of('flag')),
                'lateReports' => $this->scoped($this->reports->lateQueue($from, $to), $actor, $employees, $of('late')),
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    /**
     * How far the actor reaches on each kind right now, null for a kind they do not hold.
     *
     * @return array<string, ?string>
     */
    private function reaches(Employee $actor, Request $request): array
    {
        $reaches = [];
        foreach (self::VIEW_RULES as $kind => $rule) {
            $reaches[$kind] = PortalRules::reach($actor, $request, $rule);
        }

        return $reaches;
    }

    /**
     * The rows in reach, each named with its owner's department for the page's department filter.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, Employee>  $employees
     * @param  callable(array<string, mixed>): ?string  $reachOf
     * @return list<array<string, mixed>>
     */
    private function scoped(array $rows, Employee $actor, array $employees, callable $reachOf): array
    {
        $scoped = [];
        foreach ($rows as $row) {
            $owner = $employees[strtolower(trim((string) ($row['memberName'] ?? '')))] ?? null;
            if (PortalRules::covers($reachOf($row), $actor, $owner)) {
                $department = trim((string) ($owner->department ?? ''));
                $scoped[] = [...$row, 'department' => $department === '' ? null : $department];
            }
        }

        return $scoped;
    }

    /**
     * Composed member name to employee, keyed the way employeeNamed() matches a request's owner.
     *
     * @return array<string, Employee>
     */
    private function employeesByName(): array
    {
        $map = [];
        foreach (Employee::query()->get(['id', 'first_name', 'last_name', 'department', 'role', 'role_level']) as $employee) {
            $name = strtolower(trim(trim((string) $employee->first_name).' '.trim((string) $employee->last_name)));
            $map[$name] = $employee;
        }

        return $map;
    }

    /**
     * Decisions obey the same reach as the list: a row outside it answers "not on the queue"
     * rather than revealing that it exists somewhere the actor cannot see.
     */
    private function assertInReach(?string $reach, Employee $actor, ?Employee $owner, string $notFound): void
    {
        if (! PortalRules::covers($reach, $actor, $owner)) {
            abort(404, $notFound);
        }
    }

    /**
     * @return array{section: string, resource: string, saved: int}
     */
    public function decide(Employee $actor, Request $request): array
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:50'],
            'changes.*.id' => ['required', 'string', 'max:32'],
            'changes.*.kind' => ['required', 'in:leave,overtime,holiday-work,offset,reimbursement,flag,late'],
            'changes.*.status' => ['required', 'in:pending,approved,rejected'],
            'changes.*.approverRemarks' => ['nullable', 'string', 'max:2000'],
            // Reimbursement approvals only: the envelope the claim is charged to, and the approver's
            // explicit go-ahead when the claim is larger than what that envelope has left.
            'changes.*.envelopeId' => ['nullable', 'integer', 'min:1'],
            'changes.*.allowOverspend' => ['nullable', 'boolean'],
        ]);

        // Every kind in the batch is checked before anything is written, so a refusal changes nothing.
        foreach ($validated['changes'] as $change) {
            if (! PortalRules::holds($actor, $request, self::VIEW_RULES[$change['kind']].'.decide')) {
                abort(403, 'You cannot approve or reject '.$this->kindLabel($change['kind']).'s.');
            }
        }
        $reaches = $this->reaches($actor, $request);
        // Charges made or removed in this batch, logged once the batch has actually committed.
        $charges = [];
        $saved = $this->budget->withinLedger(function () use ($validated, $actor, $reaches, $request, &$charges): int {
            return $this->ledger->within($this->connection(), function () use ($validated, $actor, $reaches, $request, &$charges): int {
                $count = 0;
                foreach ($validated['changes'] as $change) {
                    $applied = $this->applyChange($actor, $reaches[$change['kind']], $change);
                    if (($applied['ledger'] ?? null) !== null) {
                        $charges[] = $applied['ledger'];
                    }
                    $this->notifyOwnerOfDecision($actor, $applied, $request);
                    $count++;
                }

                return $count;
            });
        });

        foreach ($charges as $entry) {
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
     * The payout proof an admin attaches once a reimbursement line item is approved -- the
     * company's evidence it paid that item back, distinct from the employee's own purchase
     * receipt. Delegates the actual attachments-table write to PortalReimbursementRequests,
     * which already owns that table, and bumps this class's own cache so the queue reflects
     * it immediately rather than waiting out the TTL.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function uploadPayoutReceipt(Employee $actor, int $itemId, Request $request): array
    {
        $this->assertInReach(PortalRules::reach($actor, $request, self::VIEW_RULES['reimbursement']), $actor, $this->claimOwner($itemId), 'That reimbursement is not on the queue.');
        $fields = $this->reimbursements->writePayoutReceipt($actor, $itemId, $request);
        $this->bumpCache();

        return [
            'section' => 'manage',
            'resource' => 'requests',
            'data' => $fields,
        ];
    }

    /**
     * @param  array{id: string, kind: string, status: string, approverRemarks?: string|null, envelopeId?: int|null, allowOverspend?: bool|null}  $change
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string, ledger?: array<string, mixed>|null}
     */
    private function applyChange(Employee $actor, ?string $reach, array $change): array
    {
        $status = $this->storedStatus($change['status']);
        $remarks = $this->text($change['approverRemarks'] ?? null);

        // A late report is named by its member and day rather than by a row, so it is the one kind
        // whose id is not a number.
        if ($change['kind'] === 'late') {
            $this->assertInReach($reach, $actor, $this->reports->lateReportOwner($change['id']), 'That late report is not on the queue.');
            $decided = $this->reports->decideLate($change['id'], $status, $remarks);
            $this->recordDecision($actor, 'late', $change['id'], [
                'id' => $change['id'],
                'userReportIds' => $decided['lineIds'],
                'reportedOn' => $decided['date'],
                'lateSubmissionApproval' => $status,
                'approverRemarks' => $remarks,
                // The sync sends changed columns only, so it needs them as a column map, not as prose.
                'fields' => [
                    'late_submission_approval' => $status,
                    'approver_remarks' => $remarks,
                ],
            ]);

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
                $reach,
                $id,
                $status,
                $remarks,
                $change['status'],
                $envelopeId === null ? null : (int) $envelopeId,
                (bool) ($change['allowOverspend'] ?? false),
            );
        }
        if ($change['kind'] === 'flag') {
            return $this->applyFlaggedReport($actor, $reach, $id, $status, $remarks, $change['status']);
        }

        return $this->applyQueuedRequest($actor, $reach, $id, $change['kind'], $status, $remarks, $change['status']);
    }

    /**
     * @param  array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}  $applied
     */
    private function notifyOwnerOfDecision(Employee $actor, array $applied, Request $request): void
    {
        $owner = $applied['owner'];
        // A flagged member must never learn they are flagged, so a flag decision leaves them no notice or log line.
        if (! $owner instanceof Employee || $applied['kind'] === 'flag') {
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
            PortalActivityCopy::YOUR.' '.$subject.' has been '.$verb.' by '.PortalActivityCopy::actorName($actor, $request),
            $applied['recordId'],
            $request,
            [
                'kind' => $applied['kind'],
                'requestedFor' => $applied['date'],
                'status' => $applied['status'],
                'decidedBy' => PortalActivityCopy::actorName($actor, $request, true),
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
            PortalActivityCopy::notifyDecided($kind, $applied['date'], $verb, PortalActivityCopy::actorName($actor, $request)),
            PortalShellPath::USER_REQUESTS,
            'Open requests',
            ['recordId' => $applied['recordId']],
        );
    }

    /**
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}
     */
    private function applyQueuedRequest(Employee $actor, ?string $reach, int $id, string $kind, string $status, ?string $remarks, string $apiStatus): array
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

        $owner = $this->employeeNamed($this->text($row->name ?? null));
        $this->assertInReach($reach, $actor, $owner, 'That request is not on the queue.');

        $this->connection()->table('requests')->where('id', $id)->update([
            'status' => $status,
            'approver_remarks' => $remarks,
        ]);

        $date = $this->calendarDate($row->request_date ?? null)
            ?? $this->calendarDate($row->original_work_day ?? null)
            ?? '';

        $this->recordDecision($actor, $kind, $id, [
            'id' => (string) $id,
            'requestedFor' => $date,
            'status' => $status,
            'approverRemarks' => $remarks,
        ]);

        return [
            'owner' => $owner,
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
        ?string $reach,
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

        $owner = $this->claimOwner($id);
        $this->assertInReach($reach, $actor, $owner, 'That reimbursement is not on the queue.');

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

        // Every item of the claim as it stood before this decision: the item decided, and the rest
        // for the one case that needs them -- a charge made when a claim was charged whole.
        $rows = $claim()->get(['id', 'item', 'cost', 'qty', 'status']);
        if ($rows->isEmpty()) {
            abort(404, 'That reimbursement is not on the queue.');
        }

        /*
         * One line item, not the whole claim. Every reimbursements row carries its own status and
         * approver_remarks, and a claim of three items is three costs an approver settles one at a
         * time -- deciding one of them used to rewrite its siblings, which is a decision nobody made.
         */
        // Existence is proven above; update() counts CHANGED rows, so a repeat decision reads 0.
        $this->connection()
            ->table('reimbursements')
            ->where('id', $id)
            ->update([
                'status' => $status,
                'approver_remarks' => $remarks,
            ]);
        $decided = $rows->first(fn (object $row): bool => (int) $row->id === $id) ?? $seed;

        $filedOn = $this->calendarDate($seed->reimb_date ?? null) ?? '';
        $this->recordDecision($actor, 'reimbursement', $id, [
            'id' => (string) $id,
            'requestedFor' => $this->calendarDate($seed->reimb_date ?? null),
            'status' => $status,
            'approverRemarks' => $remarks,
        ]);

        $memberName = $owner instanceof Employee
            ? PortalActivityCopy::displayName($owner)
            : ($name ?? 'Member');

        return [
            'owner' => $owner,
            'kind' => 'reimbursement',
            'date' => $filedOn,
            'recordId' => (string) $id,
            'status' => $apiStatus,
            'ledger' => $this->settleItemCharge(
                $actor,
                $rows->all(),
                $decided,
                $status,
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
        $owner = $this->claimOwner($claimId);

        return $owner === null ? null : $this->text($owner->department ?? null);
    }

    /**
     * Keeps one item's envelope charge in step with its decision. An item is charged to the envelope
     * the approver picks for it when it is approved, and that charge goes back the moment it stops
     * being approved. Each item carries a charge of its own, so nothing is ever put on an envelope
     * the approver did not pick for it -- not even the one its claim's other items went to.
     *
     * A charge made before items were charged one by one covers its whole claim. Setting one of that
     * claim's items back takes the item's share out of it, rather than returning all of it.
     *
     * @param  list<object>  $rows  every item of the claim, as they stood before this decision
     * @return array{action: string, message: string, recordId: string, payload: array<string, mixed>}|null
     */
    private function settleItemCharge(
        Employee $actor,
        array $rows,
        object $item,
        string $status,
        ?int $envelopeId,
        bool $allowOverspend,
        string $memberName,
        string $filedOn,
        ?string $department,
    ): ?array {
        $isApproved = $status === 'Approved';
        if (! $this->budget->isAvailable() || $this->isApproved($item->status ?? null) === $isApproved) {
            return null;
        }

        $itemId = (int) $item->id;
        $label = $this->text($item->item ?? null) ?? 'Item';
        if ($isApproved) {
            // Already carried, from before the item was last set back: nothing more to charge.
            if ($this->budget->chargeFor($itemId) !== null) {
                return null;
            }
            if ($envelopeId === null) {
                abort(422, 'Choose a budget envelope to charge this reimbursement to.');
            }

            $charge = $this->budget->charge($actor, [
                'id' => $itemId,
                'memberName' => $memberName,
                'filedOn' => $filedOn,
                'amountCents' => $this->itemCents($item),
                'items' => [$label],
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
                    'claimId' => (string) $itemId,
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

        $charge = $this->budget->chargeFor($itemId) ?? $this->wholeClaimCharge($rows, $itemId);
        if ($charge === null) {
            return null;
        }
        $left = (int) round($charge['amount'] * 100) - $this->itemCents($item);
        $carrier = $charge['claimId'] === $itemId ? $this->nextCarrier($rows, $itemId) : $charge['claimId'];

        if ($left <= 0 || $carrier === null) {
            $released = $this->budget->release($charge);

            return [
                'action' => PortalLogAction::DELETE,
                'message' => PortalActivityCopy::releasedReimbursementCharge(
                    $released['envelopeName'],
                    $this->peso($released['amount']),
                ),
                'recordId' => (string) $released['transactionId'],
                'payload' => [
                    'claimId' => (string) $released['claimId'],
                    'envelopeId' => (string) $released['envelopeId'],
                    'envelope' => $released['envelopeName'],
                    'amount' => $released['amount'],
                ],
            ];
        }

        $remaining = array_values(array_filter(
            $rows,
            fn (object $row): bool => (int) $row->id !== $itemId && $this->isApproved($row->status ?? null),
        ));
        $shrunk = $this->budget->shrink($actor, $charge, [
            'id' => $carrier,
            'memberName' => $memberName,
            'filedOn' => $filedOn,
            'amountCents' => $left,
            'items' => array_map(fn (object $row): string => $this->text($row->item ?? null) ?? 'Item', $remaining),
            'department' => $department,
        ]);

        return [
            'action' => PortalLogAction::PATCH,
            'message' => PortalActivityCopy::adjustedReimbursementCharge(
                $memberName,
                $shrunk['envelopeName'],
                $this->peso($shrunk['previousAmount']),
                $this->peso($shrunk['amount']),
            ),
            'recordId' => (string) $shrunk['transactionId'],
            'payload' => [
                'claimId' => (string) $carrier,
                'envelopeId' => (string) $shrunk['envelopeId'],
                'envelope' => $shrunk['envelopeName'],
                'previousAmount' => $shrunk['previousAmount'],
                'amount' => $shrunk['amount'],
            ],
        ];
    }

    /**
     * The charge that covers an item's whole claim, from before items were charged one by one: one
     * carried by another of the claim's items, and worth more than that item alone. An item's own
     * charge is exactly its total, so it is never mistaken for one.
     *
     * @param  list<object>  $rows
     * @return array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}|null
     */
    private function wholeClaimCharge(array $rows, int $itemId): ?array
    {
        $totals = [];
        foreach ($rows as $row) {
            if ((int) $row->id !== $itemId) {
                $totals[(int) $row->id] = $this->itemCents($row);
            }
        }
        foreach ($this->budget->chargesFor(array_keys($totals)) as $carrierId => $charge) {
            if ((int) round($charge['amount'] * 100) > ($totals[$carrierId] ?? 0)) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * The item a whole-claim charge moves to when the one carrying it is set back: the lowest of the
     * claim's other approved items that carries no charge of its own. Null when there is none.
     *
     * @param  list<object>  $rows
     */
    private function nextCarrier(array $rows, int $itemId): ?int
    {
        $approved = [];
        foreach ($rows as $row) {
            if ((int) $row->id !== $itemId && $this->isApproved($row->status ?? null)) {
                $approved[] = (int) $row->id;
            }
        }
        $carrying = $this->budget->chargesFor($approved);
        $free = array_values(array_filter($approved, fn (int $id): bool => ! isset($carrying[$id])));

        return $free === [] ? null : min($free);
    }

    private function isApproved(mixed $status): bool
    {
        return strcasecmp(trim((string) ($status ?? '')), 'Approved') === 0;
    }

    // An item's total in whole centavos: its cost times its quantity.
    private function itemCents(object $row): int
    {
        return (int) round(((float) ($row->cost ?? 0)) * 100) * $this->quantity($row->qty ?? null);
    }

    private function peso(float $amount): string
    {
        return ($amount < 0 ? '-' : '').'₱'.number_format(abs($amount), 2);
    }

    /**
     * $id is the lowest user_reports.id in the flagged group -- the same "name a multi-row group by
     * its lowest row id" convention applyClaim's own id already relies on. Every row filed in the
     * same batch (PortalSubmittedReports::create()) shares the decision: flag_status and
     * flag_remarks move together, the way they were set together. is_flag itself is never touched
     * here -- it stays the permanent record that this report was filed on a warning.
     *
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}
     */
    private function applyFlaggedReport(Employee $actor, ?string $reach, int $id, string $status, ?string $remarks, string $apiStatus): array
    {
        $seed = $this->connection()
            ->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('user_reports.id', $id)
            ->where('employees_user_reports.is_flag', 1)
            ->select([
                'user_reports.report_date',
                'user_reports.late_submission',
                'employees_user_reports.employee_id',
            ])
            ->first();
        if ($seed === null) {
            abort(404, 'That flagged report is not on the queue.');
        }

        $employeeId = (int) $seed->employee_id;
        $owner = Employee::query()->find($employeeId);
        $this->assertInReach($reach, $actor, $owner instanceof Employee ? $owner : null, 'That flagged report is not on the queue.');
        $kind = PortalSubmittedReportPresenter::kind($seed->late_submission ?? null);

        $siblings = $this->connection()
            ->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->where('user_reports.report_date', $seed->report_date)
            ->select(['user_reports.id', 'user_reports.late_submission'])
            ->get();

        $groupIds = [];
        foreach ($siblings as $sibling) {
            if (PortalSubmittedReportPresenter::kind($sibling->late_submission ?? null) === $kind) {
                $groupIds[] = (int) $sibling->id;
            }
        }

        $this->connection()
            ->table('employees_user_reports')
            ->where('employee_id', $employeeId)
            ->whereIn('user_report_id', $groupIds)
            ->update([
                'flag_status' => $status,
                'flag_remarks' => $remarks,
            ]);
        // The review is what Airtable's Approval means (2026-10-14, user).
        $approval = in_array($status, ['Approved', 'Rejected'], true) ? $status : 'Pending';
        $this->connection()->table('user_reports')->whereIn('id', $groupIds)->update(['approval' => $approval]);

        // One decision covers every line of the group, so the sync needs each line's id.
        $this->recordDecision($actor, 'flag', $id, [
            'id' => (string) $id,
            'employeeId' => $employeeId,
            'userReportIds' => $groupIds,
            'reportedOn' => $this->calendarDate($seed->report_date ?? null),
            'flagStatus' => $status,
            'flagRemarks' => $remarks,
            'fields' => ['approval' => $approval],
        ]);

        return [
            'owner' => $owner instanceof Employee ? $owner : null,
            'kind' => 'flag',
            'date' => $this->calendarDate($seed->report_date ?? null) ?? '',
            'recordId' => (string) $id,
            'status' => $apiStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function recordDecision(Employee $actor, string $kind, int|string $id, array $parameters): void
    {
        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            in_array($kind, ['flag', 'late'], true) ? 'portal.user_reports' : 'portal.requests',
            $parameters,
            // A late decision is not a filing: reports.submitted is what the sync sends as the report itself.
            $kind === 'late' ? 'reports.late' : $this->resourceForKind($kind),
            (string) $id,
            null,
            (int) $actor->getKey(),
            is_string($actor->id_no) ? $actor->id_no : null,
        );
    }

    // The employee a claim line belongs to: the junction link when it exists, else the typed name.
    private function claimOwner(int $id): ?Employee
    {
        $linkedId = $this->connection()
            ->table('employees_reimbursements')
            ->where('reimbursement_id', $id)
            ->value('employee_id');
        $owner = is_numeric($linkedId) ? Employee::query()->find((int) $linkedId) : null;
        if ($owner instanceof Employee) {
            return $owner;
        }

        $name = $this->connection()->table('reimbursements')->where('id', $id)->value('employee_name_input');

        return $this->employeeNamed(is_string($name) ? $name : null);
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
            'flag' => 'flagged report',
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
            'flag' => 'reports.flagged',
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

        $ids = $rows->map(static fn (object $row): int => (int) $row->id)->all();
        $receiptFields = $this->reimbursements->receiptFieldsByItemId($ids);
        $payoutFields = $this->reimbursements->payoutReceiptFieldsByItemId($ids);

        // The queue splits a claim by status, so its approved and pending items arrive as two rows
        // of claims. Both carry the claim's own key, so the portal can tell they are one claim.
        $groups = [];
        $claimItems = [];
        foreach ($rows as $row) {
            $groups[$this->claimKey($row)][] = $row;
            $claimItems[$this->submissionKey($row)][] = (int) $row->id;
        }

        $envelopes = $this->paidFromEnvelopes($rows->all(), $claimItems);

        $data = [];
        foreach ($groups as $group) {
            $itemIds = $claimItems[$this->submissionKey($group[0])] ?? [];
            $claim = $this->presentClaim($group, $receiptFields, $payoutFields, $envelopes, $itemIds === [] ? null : min($itemIds));
            if ($claim !== null) {
                $data[] = $claim;
            }
        }

        return $data;
    }

    /**
     * The envelope each approved item was paid from, so the queue shows where an approval drew its
     * money. An item's own charge names it; an item covered by a whole-claim charge from before
     * takes the envelope of the claim item carrying that charge.
     *
     * @param  list<object>  $rows
     * @param  array<string, list<int>>  $claimItems
     * @return array<int, string>
     */
    private function paidFromEnvelopes(array $rows, array $claimItems): array
    {
        if ($rows === [] || ! $this->budget->isAvailable()) {
            return [];
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }
        $charges = $this->budget->chargesFor(array_keys($byId));
        $names = array_map(static fn (array $charge): string => $charge['envelopeName'], $charges);

        foreach ($claimItems as $itemIds) {
            foreach ($itemIds as $carrierId) {
                $charge = $charges[$carrierId] ?? null;
                if ($charge === null || (int) round($charge['amount'] * 100) <= $this->itemCents($byId[$carrierId])) {
                    continue;
                }
                foreach ($itemIds as $itemId) {
                    if (! isset($names[$itemId]) && $this->isApproved($byId[$itemId]->status ?? null)) {
                        $names[$itemId] = $charge['envelopeName'];
                    }
                }
            }
        }

        return $names;
    }

    // The claim an item was filed in, whatever its status: the rows decide() settles together.
    private function submissionKey(object $row): string
    {
        return strtolower(trim((string) ($row->employee_name_input ?? ''))).'|'.$this->createdStamp($row->date_created ?? null);
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int, array{receiptName: ?string, receiptUrl: ?string, receiptMime: ?string, receiptThumbUrl: ?string}>  $receiptFields
     * @param  array<int, array{payoutReceiptName: ?string, payoutReceiptUrl: ?string, payoutReceiptMime: ?string, payoutReceiptThumbUrl: ?string}>  $payoutFields
     * @param  array<int, string>  $envelopes
     * @return array<string, mixed>|null
     */
    private function presentClaim(array $rows, array $receiptFields, array $payoutFields, array $envelopes, ?int $chargeKey): ?array
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
        $statuses = [];
        foreach ($rows as $row) {
            $itemId = (int) $row->id;
            $ids[] = $itemId;
            $receipt = $receiptFields[$itemId] ?? null;
            $payoutField = $payoutFields[$itemId] ?? null;
            $itemStatus = $this->claimStatus($row->status ?? null);
            $statuses[] = $itemStatus;
            $items[] = [
                'id' => (string) $itemId,
                'label' => $this->text($row->item ?? null) ?? 'Item',
                'cost' => round((float) ($row->cost ?? 0), 2),
                'quantity' => $this->quantity($row->qty ?? null),
                'teamLabel' => $this->text($row->team ?? null),
                'purpose' => $this->text($row->purpose ?? null),
                'status' => $itemStatus,
                'approverRemarks' => $this->text($row->approver_remarks ?? null),
                'receiptName' => $receipt['receiptName'] ?? null,
                'receiptUrl' => $receipt['receiptUrl'] ?? null,
                'receiptMime' => $receipt['receiptMime'] ?? null,
                'receiptThumbUrl' => $receipt['receiptThumbUrl'] ?? null,
                'payoutReceiptName' => $payoutField['payoutReceiptName'] ?? null,
                'payoutReceiptUrl' => $payoutField['payoutReceiptUrl'] ?? null,
                'payoutReceiptMime' => $payoutField['payoutReceiptMime'] ?? null,
                'payoutReceiptThumbUrl' => $payoutField['payoutReceiptThumbUrl'] ?? null,
                'envelopeName' => $envelopes[$itemId] ?? null,
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
            'status' => PortalReimbursementRequests::rolledUpStatus($statuses),
            'approverRemarks' => $approverRemarks,
            'items' => $items,
            'chargeKey' => (string) ($chargeKey ?? min($ids)),
        ];
    }

    /*
     * Status is deliberately not part of the key. It is the line's own now, so keying on it would
     * split one application into a separate claim per decision the moment its items disagree.
     */
    private function claimKey(object $row): string
    {
        $employeeId = (int) ($row->employee_id ?? 0);
        $name = strtolower(trim((string) ($row->employee_name_input ?? '')));

        return $employeeId.'|'.$name.'|'.$this->createdStamp($row->date_created ?? null);
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
