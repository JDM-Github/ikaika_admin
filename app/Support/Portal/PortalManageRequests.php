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
 * Manage / Requests: every member's leave, overtime, offset and claims for Admin/Executive.
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
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Request $request): array
    {
        [$from, $to] = $this->dateRange($request);
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:manage:requests:'.$version.':'.$from.':'.$to;

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
            'changes.*.kind' => ['required', 'in:leave,overtime,holiday-work,offset,reimbursement'],
            'changes.*.status' => ['required', 'in:pending,approved,rejected'],
            'changes.*.approverRemarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = $this->connection()->transaction(function () use ($validated, $actor, $request): int {
            $count = 0;
            foreach ($validated['changes'] as $change) {
                $this->notifyOwnerOfDecision($actor, $this->applyChange($change), $request);
                $count++;
            }

            return $count;
        });

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
     * @param  array{id: string, kind: string, status: string, approverRemarks?: string|null}  $change
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}
     */
    private function applyChange(array $change): array
    {
        $id = (int) $change['id'];
        if ($id < 1 || (string) $id !== $change['id']) {
            abort(422, 'Each change needs a numeric id.');
        }

        $status = $this->storedStatus($change['status']);
        $remarks = $this->text($change['approverRemarks'] ?? null);

        if ($change['kind'] === 'reimbursement') {
            return $this->applyClaim($id, $status, $remarks, $change['status']);
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
                'approved' => 'Request approved',
                'rejected' => 'Request rejected',
                default => 'Request updated',
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
     * @return array{owner: ?Employee, kind: string, date: string, recordId: string, status: string}
     */
    private function applyClaim(int $id, string $status, ?string $remarks, string $apiStatus): array
    {
        $seed = $this->connection()
            ->table('reimbursements')
            ->select(['id', 'date_created', 'employee_name_input', 'reimb_date', 'status'])
            ->where('id', $id)
            ->first();
        if ($seed === null) {
            abort(404, 'That reimbursement is not on the queue.');
        }

        $query = $this->connection()
            ->table('reimbursements')
            ->where('date_created', $seed->date_created);
        $name = $this->text($seed->employee_name_input ?? null);
        if ($name === null) {
            $query->where(function ($inner): void {
                $inner->whereNull('employee_name_input')->orWhere('employee_name_input', '');
            });
        } else {
            $query->where('employee_name_input', $seed->employee_name_input);
        }

        $updated = $query->update([
            'status' => $status,
            'approver_remarks' => $remarks,
        ]);
        if ($updated === 0) {
            abort(404, 'That reimbursement is not on the queue.');
        }

        $linkedId = $this->connection()
            ->table('employees_reimbursements')
            ->where('reimbursement_id', $id)
            ->value('employee_id');
        $owner = is_numeric($linkedId) ? Employee::query()->find((int) $linkedId) : null;
        if (! $owner instanceof Employee) {
            $owner = $this->employeeNamed($name);
        }

        return [
            'owner' => $owner instanceof Employee ? $owner : null,
            'kind' => 'reimbursement',
            'date' => $this->calendarDate($seed->reimb_date ?? null) ?? '',
            'recordId' => (string) $id,
            'status' => $apiStatus,
        ];
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
            default => 'reimbursement request',
        };
    }

    private function resourceForKind(string $kind): string
    {
        return match ($kind) {
            'leave' => 'requests.leave',
            'overtime', 'holiday-work' => 'requests.overtime',
            'offset' => 'requests.offset',
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

        $receiptFields = $this->reimbursements->receiptFieldsByItemId(
            $rows->map(static fn (object $row): int => (int) $row->id)->all(),
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->claimKey($row)][] = $row;
        }

        $data = [];
        foreach ($groups as $group) {
            $claim = $this->presentClaim($group, $receiptFields);
            if ($claim !== null) {
                $data[] = $claim;
            }
        }

        return $data;
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int, array{receiptName: ?string, receiptUrl: ?string, receiptMime: ?string, receiptThumbUrl: ?string}>  $receiptFields
     * @return array<string, mixed>|null
     */
    private function presentClaim(array $rows, array $receiptFields): ?array
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
            $receipt = $receiptFields[$itemId] ?? null;
            $items[] = [
                'id' => (string) $itemId,
                'label' => $this->text($row->item ?? null) ?? 'Item',
                'cost' => round((float) ($row->cost ?? 0), 2),
                'quantity' => $this->quantity($row->qty ?? null),
                'teamLabel' => $this->text($row->team ?? null),
                'purpose' => $this->text($row->purpose ?? null),
                'receiptName' => $receipt['receiptName'] ?? null,
                'receiptUrl' => $receipt['receiptUrl'] ?? null,
                'receiptMime' => $receipt['receiptMime'] ?? null,
                'receiptThumbUrl' => $receipt['receiptThumbUrl'] ?? null,
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
