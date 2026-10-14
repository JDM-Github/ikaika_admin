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

    /** The decision sent => the approval it writes; pending sends a decided project back to waiting. */
    private const DECISIONS = [
        'approve' => PortalProjectApproval::APPROVED,
        'reject' => PortalProjectApproval::REJECTED,
        'pending' => PortalProjectApproval::PENDING,
    ];

    private const TRAILS = [
        PortalProjectApproval::APPROVED => PortalProjectTrail::APPROVED,
        PortalProjectApproval::REJECTED => PortalProjectTrail::REJECTED,
        PortalProjectApproval::PENDING => PortalProjectTrail::REOPENED,
    ];

    // A Closed project's approval is its closing, so its trail says so rather than "creation".
    private const CLOSING_TRAILS = [
        PortalProjectApproval::APPROVED => PortalProjectTrail::CLOSING_APPROVED,
        PortalProjectApproval::REJECTED => PortalProjectTrail::CLOSING_REJECTED,
        PortalProjectApproval::PENDING => PortalProjectTrail::CLOSING_REOPENED,
    ];

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
        private readonly PortalProjectRepositories $repositories,
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
        $rows = $query->get()->values();
        // A completed or closed project's approval is its department head's alone (2026-10-13, user).
        $items = $this->items($rows->all());
        foreach ($rows as $index => $row) {
            $items[$index]['canDecide'] = ! PortalProjectPowers::isLocked($actor, $request, $row);
        }

        return [
            'section' => 'manage',
            'resource' => 'projects',
            'data' => $items,
            'counts' => $counts,
            // An administrator may change a project's approval after it was decided (2026-10-13, user).
            'canChangeDecided' => PortalRole::isAdmin($actor->role, $actor->role_level),
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
            'decision' => ['required', 'string', Rule::in(array_keys(self::DECISIONS))],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $status = self::DECISIONS[$validated['decision']];
        $isApproved = $status === PortalProjectApproval::APPROVED;
        $isReopened = $status === PortalProjectApproval::PENDING;
        $remarks = $this->text($validated['remarks'] ?? null);
        if ($status === PortalProjectApproval::REJECTED && $remarks === null) {
            abort(422, 'Say why the project is rejected, so whoever asked can fix it.');
        }

        $row = $this->connection()->table('projects')->where('id', $projectId)->whereNull('deleted_at')->first();
        if ($row === null) {
            abort(404, 'That project was not found.');
        }
        if (! $this->approval->covers($actor, $request, $row)) {
            abort(403, 'Deciding on this project needs Project Approver over it.');
        }
        if (PortalProjectPowers::isLocked($actor, $request, $row)) {
            abort(403, 'This project is completed or closed. Only its department head can change its approval.');
        }
        // Only an administrator changes a project that was already decided; anyone else decides waiting ones.
        if ($row->project_creation_status !== PortalProjectApproval::PENDING && ! PortalRole::isAdmin($actor->role, $actor->role_level)) {
            abort(422, 'This project is not waiting for approval.');
        }
        if ($row->project_creation_status === $status) {
            abort(422, 'The project is already '.strtolower($status === PortalProjectApproval::PENDING ? 'waiting' : $status).'.');
        }
        $name = $this->text($row->project_name ?? null) ?? 'Project';
        $decision = $isReopened ? 'pending' : ($isApproved ? 'approved' : 'rejected');

        $trail = (($row->status ?? null) === 'Closed' ? self::CLOSING_TRAILS : self::TRAILS)[$status];

        $this->ledger->within($this->connection(), function () use ($actor, $request, $projectId, $status, $isReopened, $decision, $remarks, $name, $trail): void {
            // Back to waiting clears who decided, as a project that was never decided has no approver.
            $written = [
                'project_creation_status' => $status,
                'project_creation_approver' => $isReopened ? null : $this->personName($actor),
                'project_approver_email' => $isReopened ? null : $this->text($actor->email ?? null),
                'project_creation_approver_remarks' => $remarks,
                'date_modified' => now(),
            ];
            $this->connection()->table('projects')->where('id', $projectId)->update($written);
            $this->ledger->recordEdit(
                CoreLedger::PRODUCT_PORTAL,
                self::CORE_TARGET,
                // The sync sends changed columns only, so it needs them as a column map, not as prose.
                ['id' => (string) $projectId, 'projectName' => $name, 'decision' => $decision, 'remarks' => $remarks, 'fields' => $written],
                self::CORE_RESOURCE,
                (string) $projectId,
                CoreRecycleKey::project($projectId),
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            $this->trail->record($projectId, $trail, $actor, $request, $remarks);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            $isReopened ? PortalActivityCopy::reopenedProject($name) : PortalActivityCopy::decidedProject($name, $isApproved),
            (string) $projectId,
            $request,
            ['projectName' => $name, 'decision' => $decision],
        );
        $requester = ($row->requested_by ?? null) === null ? null : Employee::query()->find((int) $row->requested_by);
        if ($requester instanceof Employee && $isReopened) {
            $this->audit->notifyIfOther(
                $requester,
                $actor,
                PortalNotificationType::PROJECT_MODIFIED,
                'Project back to waiting',
                PortalActivityCopy::notifyProjectReopened(PortalActivityCopy::actorName($actor, $request), $name),
                PortalShellPath::CREATED_PROJECTS,
                'Open Created Projects',
                ['recordId' => (string) $projectId],
            );
        } elseif ($requester instanceof Employee) {
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

    /**
     * Everything the Add / Edit Project form carries, for one project the approver covers, so Manage /
     * Projects can open it read-only before anyone edits it.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function show(Employee $actor, Request $request, int $projectId): array
    {
        $row = $this->connection()->table('projects')->where('id', $projectId)->whereNull('deleted_at')->first();
        if ($row === null) {
            abort(404, 'That project was not found.');
        }
        if (! $this->approval->covers($actor, $request, $row)) {
            abort(403, 'Opening this project needs Project Approver over it.');
        }

        return [
            'section' => 'manage',
            'resource' => 'projects',
            // The sheet's Edit button: a completed or closed project stays shut to all but its department head.
            'data' => [...$this->detail($row), 'canEdit' => PortalProjectPowers::opensEditor($actor, $request, $row)],
        ];
    }

    /**
     * The project as that form would show it: every column the form writes, its client, its people
     * split by the role on the junction, its scopes and its repositories.
     *
     * @return array<string, mixed>
     */
    private function detail(object $row): array
    {
        $projectId = (int) $row->id;
        $levels = ['lod' => $this->text($row->lod ?? null)];
        foreach (PortalProjectEditor::LEVEL_COLUMNS as $discipline => $column) {
            $levels[$discipline] = $this->text($row->{$column} ?? null);
        }
        $links = [];
        foreach (PortalProjectEditor::LINK_COLUMNS as $kind => $column) {
            $links[$kind] = $this->text($row->{$column} ?? null);
        }

        // One junction, two roles: the form shows members and support members apart.
        $memberNames = [];
        $supportNames = [];
        $people = $this->connection()->table('employees_projects')
            ->join('employees', 'employees.id', '=', 'employees_projects.employee_id')
            ->where('employees_projects.project_id', $projectId)
            ->orderBy('employees.id')
            ->get(['employees.first_name', 'employees.last_name', 'employees_projects.role_on_project']);
        foreach ($people as $person) {
            $name = $this->personName($person);
            if (($person->role_on_project ?? null) === 'support') {
                $supportNames[] = $name;
            } else {
                $memberNames[] = $name;
            }
        }

        $client = $this->connection()->table('projects_clients')
            ->join('clients', 'clients.id', '=', 'projects_clients.client_id')
            ->where('projects_clients.project_id', $projectId)
            ->orderBy('clients.id')
            ->value('clients.name');
        $scopes = [];
        $scopeRows = $this->connection()->table('projects_project_scopes')
            ->join('project_scopes', 'project_scopes.id', '=', 'projects_project_scopes.project_scope_id')
            ->where('projects_project_scopes.project_id', $projectId)
            ->orderBy('project_scopes.id')
            ->get(['project_scopes.id_no']);
        foreach ($scopeRows as $scope) {
            // A scope is its WBS code; the table carries no name of its own.
            $scopes[] = (string) ($scope->id_no ?? '');
        }

        $leadEmail = strtolower(trim((string) ($row->project_lead_email ?? '')));
        $lead = $leadEmail === '' ? null : $this->connection()->table('employees')
            ->where($this->connection()->raw('LOWER(email)'), $leadEmail)
            ->first(['first_name', 'last_name']);
        $requester = ($row->requested_by ?? null) === null
            ? null
            : $this->connection()->table('employees')->where('id', (int) $row->requested_by)->first(['first_name', 'last_name']);
        $requestedAt = $row->requested_at ?? null;

        return [
            'id' => (string) $projectId,
            'name' => $this->text($row->project_name ?? null) ?? 'Project',
            'projectNumber' => $this->text($row->project_number ?? null),
            'department' => $this->text($row->department ?? null),
            'typeLabel' => $this->text($row->type_of_job ?? null),
            'projectStatus' => PortalViewProjectPresenter::status($row->status ?? null),
            'area' => ($row->area_sqft ?? null) === null ? null : (string) $row->area_sqft,
            'leadName' => $lead === null ? null : $this->personName($lead),
            'leadEmail' => $this->text($row->project_lead_email ?? null),
            'clientName' => $this->text($client),
            'memberNames' => $memberNames,
            'supportMemberNames' => $supportNames,
            'levels' => $levels,
            'links' => $links,
            'repositories' => $this->repositories->forProject($projectId),
            'scopes' => $scopes,
            'creationStatus' => PortalViewProjectPresenter::creationStatus($row->project_creation_status ?? null),
            'requestedByName' => $requester === null ? null : $this->personName($requester),
            'requestedAt' => $requestedAt === null ? null : PortalViewProjectPresenter::instant($requestedAt),
            'approverName' => $this->text($row->project_creation_approver ?? null),
            'remarks' => $this->text($row->project_creation_approver_remarks ?? null),
        ];
    }

    private function tab(Request $request): string
    {
        $tab = $request->query('status');

        return is_string($tab) && array_key_exists($tab, self::STATUSES) ? $tab : 'pending';
    }

    // The live projects the Approver reach covers.
    private function covered(Employee $actor, ?string $reach): Builder
    {
        return PortalProjectApproval::scope($this->connection()->table('projects')->whereNull('deleted_at'), $actor, $reach);
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

        $memberNames = $this->projects->memberNamesByProject(array_map(static fn (object $row): int => (int) $row->id, $rows));

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
                'memberNames' => $memberNames[(int) $row->id] ?? [],
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
