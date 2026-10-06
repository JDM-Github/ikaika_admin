<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manage / Projects (2026-10-01): the projects waiting for approval, and the ones decided, for the
 * departments the person's Project Manager reach covers. Approving or rejecting writes the project,
 * the ledger, the Trail history and the log, and tells whoever asked.
 *
 * A waiting project stays usable meanwhile (the board marks it), as Airtable's Pending ones always
 * were; a rejected one stays with its reason, for its requester to edit and send again.
 */
final class PortalManageProjects
{
    private const CORE_TARGET = 'portal.projects';

    private const CORE_RESOURCE = 'projects';

    private const DECIDED_LIMIT = 200;

    /** The tab => the word projects.project_creation_status holds. */
    private const STATUSES = [
        'pending' => PortalProjectApproval::PENDING,
        'approved' => PortalProjectApproval::APPROVED,
        'rejected' => PortalProjectApproval::REJECTED,
    ];

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalAudit $audit,
        private readonly PortalProjectTrail $trail,
        private readonly PortalProjectApproval $approval,
        private readonly PortalViewProjects $projects,
    ) {}

    /**
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(Employee $actor, Request $request): array
    {
        $tab = $this->tab($request);
        $reach = PortalRules::reach($actor, $request, PortalProjectApproval::RULE);

        $counts = [];
        foreach (self::STATUSES as $key => $status) {
            $counts[$key] = $this->covered($actor, $reach)->where('project_creation_status', $status)->count();
        }

        $query = $this->covered($actor, $reach)->where('project_creation_status', self::STATUSES[$tab]);
        $query = $tab === 'pending'
            ? $query->orderByRaw('requested_at IS NULL')->orderBy('requested_at')->orderBy('id')
            : $query->orderByDesc('date_modified')->orderByDesc('id')->limit(self::DECIDED_LIMIT);
        $rows = $query->get();

        return [
            'section' => 'manage',
            'resource' => 'projects',
            'data' => $this->items($rows->all()),
            'counts' => $counts,
        ];
    }

    /**
     * Created Projects (2026-10-06): every project the person added, whatever its approval, in one read so
     * the page switches its Waiting / Approved / Rejected tabs without asking again.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function mine(Employee $actor, Request $request): array
    {
        $own = fn (): Builder => $this->connection()->table('projects')
            ->whereNull('deleted_at')
            ->where('requested_by', (int) $actor->getKey());

        $counts = [];
        foreach (self::STATUSES as $key => $status) {
            $counts[$key] = $own()->where('project_creation_status', $status)->count();
        }
        $rows = $own()->orderByDesc('requested_at')->orderByDesc('id')->limit(self::DECIDED_LIMIT)->get();

        return [
            'section' => 'projects',
            'resource' => 'mine',
            'data' => $this->items($rows->all()),
            'counts' => $counts,
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function decide(Employee $actor, Request $request, int $projectId): array
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $isApproved = $validated['decision'] === 'approve';
        $remarks = $this->text($validated['remarks'] ?? null);
        if (! $isApproved && $remarks === null) {
            abort(422, 'Say why the project is rejected, so whoever asked can fix it.');
        }

        $row = $this->connection()->table('projects')->where('id', $projectId)->whereNull('deleted_at')->first();
        if ($row === null) {
            abort(404, 'That project was not found.');
        }
        if (! $this->approval->covers($actor, $request, $row->department ?? null)) {
            abort(403, 'Deciding on this project needs Project Manager for its department.');
        }
        if ($row->project_creation_status !== PortalProjectApproval::PENDING) {
            abort(422, 'This project is not waiting for approval.');
        }
        $name = $this->text($row->project_name ?? null) ?? 'Project';

        $this->ledger->within($this->connection(), function () use ($actor, $request, $projectId, $isApproved, $remarks, $name): void {
            $this->connection()->table('projects')->where('id', $projectId)->update([
                'project_creation_status' => $isApproved ? PortalProjectApproval::APPROVED : PortalProjectApproval::REJECTED,
                'project_creation_approver' => $this->personName($actor),
                'project_approver_email' => $this->text($actor->email ?? null),
                'project_creation_approver_remarks' => $remarks,
                'date_modified' => now(),
            ]);
            $this->ledger->recordEdit(
                CoreLedger::PRODUCT_PORTAL,
                self::CORE_TARGET,
                ['id' => (string) $projectId, 'projectName' => $name, 'decision' => $isApproved ? 'approved' : 'rejected', 'remarks' => $remarks],
                self::CORE_RESOURCE,
                (string) $projectId,
                CoreRecycleKey::project($projectId),
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            $this->trail->record($projectId, $isApproved ? PortalProjectTrail::APPROVED : PortalProjectTrail::REJECTED, $actor, $request, $remarks);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::decidedProject($name, $isApproved),
            (string) $projectId,
            $request,
            ['projectName' => $name, 'decision' => $isApproved ? 'approved' : 'rejected'],
        );
        $requester = ($row->requested_by ?? null) === null ? null : Employee::query()->find((int) $row->requested_by);
        if ($requester instanceof Employee) {
            $this->audit->notifyIfOther(
                $requester,
                $actor,
                $isApproved ? PortalNotificationType::PROJECT_APPROVED : PortalNotificationType::PROJECT_REJECTED,
                $isApproved ? 'Project approved' : 'Project rejected',
                PortalActivityCopy::notifyProjectDecided(PortalActivityCopy::actorName($actor, $request), $name, $isApproved, $remarks),
                // A rejected project may not be on the requester's board, but it is always on Created Projects.
                $isApproved ? PortalShellPath::VIEW_PROJECTS : PortalShellPath::CREATED_PROJECTS,
                $isApproved ? 'Open projects' : 'Open Created Projects',
                ['recordId' => (string) $projectId],
            );
        }
        $this->projects->bumpCache();

        $fresh = $this->connection()->table('projects')->where('id', $projectId)->first();

        return [
            'section' => 'manage',
            'resource' => 'projects',
            'data' => $this->items($fresh === null ? [] : [$fresh])[0] ?? [],
        ];
    }

    private function tab(Request $request): string
    {
        $tab = $request->query('status');

        return is_string($tab) && array_key_exists($tab, self::STATUSES) ? $tab : 'pending';
    }

    // The live projects whose department the reach covers: all, the person's own, or none.
    private function covered(Employee $actor, ?string $reach): Builder
    {
        $query = $this->connection()->table('projects')->whereNull('deleted_at');
        if ($reach === 'all') {
            return $query;
        }
        $department = $reach === 'department' ? PortalPreview::department($actor->department) : '';
        if ($department === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw('LOWER(TRIM(department)) = ?', [$department]);
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function items(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $emails = [];
        $requesterIds = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row->project_lead_email ?? '')));
            if ($email !== '') {
                $emails[] = $email;
            }
            if (($row->requested_by ?? null) !== null) {
                $requesterIds[] = (int) $row->requested_by;
            }
        }
        $people = $this->connection()->table('employees')
            ->where(function ($query) use ($emails, $requesterIds): void {
                $query->whereIn('id', $requesterIds === [] ? [0] : $requesterIds)
                    ->orWhereIn($this->connection()->raw('LOWER(email)'), $emails === [] ? [''] : $emails);
            })
            ->get(['id', 'email', 'first_name', 'last_name']);
        $byId = [];
        $byEmail = [];
        foreach ($people as $person) {
            $name = $this->personName($person);
            $byId[(int) $person->id] = $name;
            $email = strtolower(trim((string) ($person->email ?? '')));
            if ($email !== '') {
                $byEmail[$email] = $name;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $leadEmail = strtolower(trim((string) ($row->project_lead_email ?? '')));
            $requestedAt = $row->requested_at ?? null;
            $items[] = [
                'id' => (string) (int) $row->id,
                'name' => $this->text($row->project_name ?? null) ?? 'Project',
                'department' => $this->text($row->department ?? null),
                'typeLabel' => $this->text($row->type_of_job ?? null),
                'projectStatus' => PortalViewProjectPresenter::status($row->status ?? null),
                'leadName' => $leadEmail === '' ? null : ($byEmail[$leadEmail] ?? null),
                'requestedByName' => ($row->requested_by ?? null) === null ? null : ($byId[(int) $row->requested_by] ?? null),
                'requestedAt' => $requestedAt === null ? null : PortalViewProjectPresenter::instant($requestedAt),
                'creationStatus' => PortalViewProjectPresenter::creationStatus($row->project_creation_status ?? null),
                'approverName' => $this->text($row->project_creation_approver ?? null),
                'remarks' => $this->text($row->project_creation_approver_remarks ?? null),
            ];
        }

        return $items;
    }

    private function personName(object $person): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($person->first_name ?? null) ? $person->first_name : null,
            is_string($person->last_name ?? null) ? $person->last_name : null,
        );
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
        return Model::getConnectionResolver()->connection('portal');
    }
}
