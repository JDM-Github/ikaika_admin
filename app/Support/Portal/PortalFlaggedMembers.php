<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\UserReport;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Manage / Requests' Flagged users: putting a person on report review and taking them off it.
 *
 * A flag is a `warnings` row in status Active, linked through `employees_warnings` -- the same record
 * Airtable's warnings arrive as, and the one PortalSubmittedReports reads when it marks a filing for
 * review. Taking a flag off sets it Inactive rather than deleting it, so the history stays.
 *
 * The flagged person is never told: there is no notice to them and no line in their own log. Only the
 * approver's act is logged, under the approver. Flagging follows Approve or reject on Flag Reports and
 * reaches only as far as that rule does.
 */
final class PortalFlaggedMembers
{
    public const RULE = 'manage.requests.flagged.decide';

    // Approve or reject carries no reach of its own; how far it goes is Flag Reports' reach, as on the queue.
    private const REACH_RULE = 'manage.requests.flagged';

    public const RESOURCE = 'manage.flagged-members';

    private const ACTIVE = 'Active';

    private const INACTIVE = 'Inactive';

    // What Airtable's own warnings carry: a flag is a review hold, not a recorded violation.
    private const VIOLATION_TYPE = 'N/A';

    public function __construct(
        private readonly PortalAudit $audit,
        private readonly CoreLedger $ledger,
    ) {}

    /**
     * Everyone in reach who is flagged, newest flag first, and everyone in reach who could be.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    public function list(Employee $actor, Request $request): array
    {
        $reach = PortalRules::reach($actor, $request, self::REACH_RULE);
        $flags = $this->activeFlags();
        $flagged = [];
        foreach ($flags as $flag) {
            if (PortalRules::covers($reach, $actor, $flag['employee'])) {
                $flagged[] = $this->presentFlag($flag);
            }
        }

        $candidates = [];
        $people = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('first_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'id_no', 'first_name', 'last_name', 'department', 'role', 'role_level']);
        foreach ($people as $person) {
            $id = (int) $person->getKey();
            if ($id === (int) $actor->getKey() || isset($flags[$id]) || ! PortalRules::covers($reach, $actor, $person)) {
                continue;
            }
            $candidates[] = [
                'employeeId' => (string) $id,
                'memberName' => $this->nameOf($person),
                'referenceCode' => PortalSubmittedReportPresenter::referenceCode($person->id_no, $id),
                'department' => $this->text($person->department),
            ];
        }

        return [
            'section' => 'manage',
            'resource' => 'flagged-members',
            'data' => $flagged,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    public function flag(Employee $actor, Request $request): array
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $reason = $this->text($validated['reason']);
        if ($reason === null) {
            abort(422, 'Say why this person is flagged, so the next approver knows.');
        }
        $member = $this->memberInReach($actor, $request, (int) $validated['employeeId']);
        if ((int) $member->getKey() === (int) $actor->getKey()) {
            abort(422, 'You cannot flag yourself.');
        }
        if ($this->activeWarningIds((int) $member->getKey()) !== []) {
            abort(422, $this->nameOf($member).' is already flagged.');
        }

        $this->ledger->within($this->connection(), function () use ($member, $reason, $actor): void {
            $warningId = (int) $this->connection()->table('warnings')->insertGetId([
                'warning_date' => Carbon::now(config('app.timezone'))->toDateString(),
                'description' => $reason,
                'status' => self::ACTIVE,
                'violation_type' => self::VIOLATION_TYPE,
                'date_created' => now(),
            ]);
            $this->connection()->table('employees_warnings')->insert([
                'employee_id' => (int) $member->getKey(),
                'warning_id' => $warningId,
            ]);
            // Airtable keeps its warnings too, so flagging is ledgered for the sync (2026-10-14, user).
            $this->ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'warnings:'.$warningId, CoreLedger::PRODUCT_PORTAL.'.warnings', ['id' => (string) $warningId], 'warnings', (string) $warningId, (int) $actor->getKey(), is_string($actor->id_no) ? $actor->id_no : null);
        });

        // The approver's own log only: the flagged person must never learn they are flagged.
        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            self::RESOURCE,
            PortalActivityCopy::flaggedMember($this->nameOf($member)),
            (string) $member->getKey(),
            $request,
            ['memberId' => (string) $member->getKey(), 'reason' => $reason],
        );

        return $this->list($actor, $request);
    }

    /**
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    public function unflag(Employee $actor, Request $request, int $employeeId): array
    {
        $member = $this->memberInReach($actor, $request, $employeeId);
        $warningIds = $this->activeWarningIds((int) $member->getKey());
        if ($warningIds === []) {
            abort(404, $this->nameOf($member).' is not flagged.');
        }

        $this->ledger->within($this->connection(), function () use ($warningIds, $actor): void {
            $this->connection()->table('warnings')->whereIn('id', $warningIds)->update(['status' => self::INACTIVE]);
            foreach ($warningIds as $warningId) {
                $this->ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, CoreLedger::PRODUCT_PORTAL.'.warnings', ['id' => (string) $warningId, 'fields' => ['status' => self::INACTIVE]], 'warnings', (string) $warningId, null, (int) $actor->getKey(), is_string($actor->id_no) ? $actor->id_no : null);
            }
        });

        $this->audit->record(
            $actor,
            PortalLogAction::DELETE,
            self::RESOURCE,
            PortalActivityCopy::unflaggedMember($this->nameOf($member)),
            (string) $member->getKey(),
            $request,
            ['memberId' => (string) $member->getKey()],
        );

        return $this->list($actor, $request);
    }

    /**
     * Someone the actor may flag: outside the reach answers "not found" rather than revealing they exist.
     */
    private function memberInReach(Employee $actor, Request $request, int $employeeId): Employee
    {
        $member = Employee::query()->find($employeeId);
        if (! $member instanceof Employee || ! PortalRules::covers(PortalRules::reach($actor, $request, self::REACH_RULE), $actor, $member)) {
            abort(404, 'That person was not found.');
        }

        return $member;
    }

    /**
     * @return list<int>
     */
    private function activeWarningIds(int $employeeId): array
    {
        return $this->connection()
            ->table('employees_warnings')
            ->join('warnings', 'warnings.id', '=', 'employees_warnings.warning_id')
            ->where('employees_warnings.employee_id', $employeeId)
            ->where('warnings.status', self::ACTIVE)
            ->pluck('warnings.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Each flagged person once, with their newest Active warning, keyed by employee id.
     *
     * @return array<int, array{employee: Employee, since: ?string, reason: ?string}>
     */
    private function activeFlags(): array
    {
        $rows = $this->connection()
            ->table('employees_warnings')
            ->join('warnings', 'warnings.id', '=', 'employees_warnings.warning_id')
            ->where('warnings.status', self::ACTIVE)
            ->orderByDesc('warnings.warning_date')
            ->orderByDesc('warnings.id')
            ->get(['employees_warnings.employee_id', 'warnings.warning_date', 'warnings.description']);
        $people = Employee::query()
            ->whereIn('id', $rows->pluck('employee_id')->unique()->all())
            ->get(['id', 'id_no', 'first_name', 'last_name', 'department', 'role', 'role_level'])
            ->keyBy('id');

        $flags = [];
        foreach ($rows as $row) {
            $id = (int) $row->employee_id;
            $person = $people->get($id);
            if (! $person instanceof Employee || isset($flags[$id])) {
                continue;
            }
            $flags[$id] = [
                'employee' => $person,
                'since' => $this->calendarDate($row->warning_date ?? null),
                'reason' => $this->text($row->description ?? null),
            ];
        }

        return $flags;
    }

    /**
     * @param  array{employee: Employee, since: ?string, reason: ?string}  $flag
     * @return array<string, mixed>
     */
    private function presentFlag(array $flag): array
    {
        $person = $flag['employee'];

        return [
            'employeeId' => (string) $person->getKey(),
            'memberName' => $this->nameOf($person),
            'referenceCode' => PortalSubmittedReportPresenter::referenceCode($person->id_no, $person->getKey()),
            'department' => $this->text($person->department),
            'since' => $flag['since'],
            'reason' => $flag['reason'],
        ];
    }

    private function nameOf(Employee $person): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($person->first_name) ? $person->first_name : null,
            is_string($person->last_name) ? $person->last_name : null,
        );
    }

    private function calendarDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function connection(): Connection
    {
        return UserReport::query()->getConnection();
    }
}
