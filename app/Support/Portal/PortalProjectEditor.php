<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Adding a project, editing it, and saving the scopes chosen in Manage Scopes.
 *
 * Portal-only (2026-10-01): the rows land in SQL and the ledger, never in the Airtable sync map, so
 * the sync lists their actions as not mapped and sends nothing. A project added by someone Project
 * Manager covers for its department is Approved at once, with them as approver; any other waits as
 * Pending on Manage / Projects (PortalProjectApproval), and saving a Rejected one sends it again.
 *
 * Adding needs the Add projects power. Editing and scopes follow Edit projects and Manage scopes at
 * a reach covering the project (PortalProjectPowers); own reach is the projects the person leads. The pm rows on employees_projects come from Airtable and are left as
 * they are; only the member and support rows follow the form.
 *
 * Every save also writes the project's Trail history (PortalProjectTrail); an edit's row says what
 * changed, and an edit that changed nothing writes none.
 *
 * A new project is numbered YY + its client's two-digit code + that client's next two-digit counter
 * for the year (260202: 2026, client 02, its second project), the year as Manila keeps it. The number
 * leads the stored name ("260202 Stockton Facility"), as on every project from Airtable, and is kept
 * in project_number; it never changes, and is never given twice, deleted projects included. The form
 * edits the name without it.
 *
 * An Engineering project needs all six links, an area above 0 and a level of detail for each
 * discipline; any other department's links are optional, though a filled one is still checked. No two live projects share a
 * name, whatever its case, spacing or number.
 */
final class PortalProjectEditor
{
    private const CORE_TARGET = 'portal.projects';

    private const CORE_RESOURCE = 'projects';

    private const MEMBER_ROLE = 'member';

    private const SUPPORT_ROLE = 'support';

    /** The portal's status => the word projects.status holds, which is Airtable's. */
    private const STATUSES = [
        'ongoing' => 'Started',
        'completed' => 'Done',
        'on-hold' => 'On Hold',
        'cancelled' => 'Cancelled',
    ];

    /** Stored as 'LOD 300'; 'N/A' is stored as it is. */
    private const LEVELS = ['100', '200', '250', '300', '350', '400', '500', 'N/A'];

    private const STATUS_LABELS = [
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
        'on-hold' => 'On Hold',
        'cancelled' => 'Cancelled',
    ];

    public const LEVEL_COLUMNS = [
        'structural' => 'st_lod',
        'architectural' => 'ar_lod',
        'electrical' => 'el_lod',
        'plumbing' => 'pl_lod',
        'mechanical-duct' => 'md_lod',
        'fire-protection' => 'fp_lod',
    ];

    public const LINK_COLUMNS = [
        'forma' => 'forma_link',
        'ms-teams' => 'msteams_project_link',
        'ms-planner' => 'ms_planner_link',
        'ms-loop' => 'ms_loop_link',
        'panoramic' => 'panoramic_link',
        'google-drive' => 'google_drive_folder_path',
    ];

    // Only Engineering projects carry these; any other department's project has them cleared on save.
    private const ENGINEERING_LINKS = ['forma', 'panoramic'];

    /** A drive path is a folder path people paste, not always a URL. */
    private const FREE_TEXT_LINKS = ['google-drive'];

    private const SCOPE_TABLES = [
        'project_scope_t1_processes',
        'project_scope_t2_procedures',
        'project_scope_t3_activities',
        'project_scope_t4_tasks',
    ];

    private const LEVEL_LABELS = [
        'structural' => 'Structural',
        'architectural' => 'Architectural',
        'electrical' => 'Electrical',
        'plumbing' => 'Plumbing',
        'mechanical-duct' => 'Mechanical Duct',
        'fire-protection' => 'Fire Protection',
    ];

    private const LINK_LABELS = [
        'forma' => 'Forma',
        'ms-teams' => 'MS Teams',
        'ms-planner' => 'MS Planner',
        'ms-loop' => 'MS Loop',
        'panoramic' => 'Panoramic Photos',
        'google-drive' => 'Google Drive Folder',
    ];

    // Project numbers follow the company's year, wherever the person adding is.
    private const COMPANY_ZONE = 'Asia/Manila';

    private const MAX_PEOPLE = 200;

    private const MAX_SCOPES = 300;

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalViewProjects $projects,
        private readonly PortalAudit $audit,
        private readonly PortalProjectTrail $trail,
        private readonly PortalProjectApproval $approval,
        private readonly PortalProjectRepositories $repositories,
    ) {}

    /**
     * What the form picks from: active employees (only those with an email can lead, since the lead is
     * stored by email), clients, and the departments the company staffs and the job types projects use.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function options(Employee $actor, Request $request): array
    {
        // Edit projects at own opens the form only for someone who leads a project.
        $editReach = PortalRules::reach($actor, $request, PortalProjectPowers::EDIT);
        if (! PortalRules::holds($actor, $request, PortalProjectPowers::CREATE)
            && ! in_array($editReach, ['department', 'all'], true)
            && ($editReach === null || ! $this->leadsAnyProject($actor))) {
            abort(403, 'The project form needs the Add projects or Edit projects power.');
        }

        // Who may lead, and where: Project Lead at all leads anywhere, at department (or own) their own department's.
        $leadReaches = PortalProjectPowers::leadReaches();
        $employees = [];
        foreach ($this->activeEmployees() as $row) {
            $reach = $this->text($row->email) === null ? null : ($leadReaches[(int) $row->id] ?? null);
            $employees[] = [
                'id' => (string) (int) $row->id,
                'name' => $this->personName($row),
                'department' => $this->text($row->department),
                'canLead' => $reach !== null,
                'leadsAnywhere' => $reach === 'all',
                'leadDepartment' => $reach === null || $reach === 'all' ? null : $this->text($row->department),
            ];
        }
        $departments = $this->departments();
        $addReach = PortalRules::reach($actor, $request, PortalProjectPowers::CREATE);
        $addDepartments = array_values(array_filter(
            $departments,
            static fn (string $department): bool => PortalProjectPowers::reachesDepartment($addReach, $actor->department, $department),
        ));

        $counters = $this->usedCounters($this->year());
        $clients = [];
        foreach ($this->connection()->table('clients')->orderBy('name')->get(['id', 'name', 'client_id']) as $row) {
            $name = $this->text($row->name);
            if ($name !== null) {
                $code = self::clientCode($row->client_id);
                $next = $code === null ? null : ($counters[$code] ?? 0) + 1;
                $clients[] = [
                    'id' => (string) (int) $row->id,
                    'name' => $name,
                    'code' => $code,
                    // What Add Project shows before saving; the save takes the next free one then.
                    'nextNumber' => $next === null || $next > 99 ? null : $this->year().$code.sprintf('%02d', $next),
                ];
            }
        }

        return [
            'section' => 'projects',
            'resource' => 'options',
            'data' => [
                'employees' => $employees,
                'clients' => $clients,
                'departments' => $departments,
                'addDepartments' => $addDepartments,
                'types' => $this->jobTypes(),
                // Job types by the department that uses them, so the form offers only the chosen one's.
                'typesByDepartment' => $this->typesByDepartment(),
                // Which activity codes each job type may use on a report, keyed by job type.
                'allowedActivitiesByType' => $this->allowedActivitiesByType(),
                // Editing a job type's allowed activities is a global change, so it needs All reach.
                'canManageAllowedActivities' => PortalRules::reach($actor, $request, PortalProjectPowers::EDIT) === 'all',
                // A project that is not Engineering's picks its scopes from these, by its department.
                'activityCodes' => PortalActivityScopes::all(),
            ],
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function show(Employee $actor, Request $request, int $projectId): array
    {
        $row = $this->liveProjectOrFail($projectId);
        $this->assertMay($actor, $row, $request, PortalProjectPowers::EDIT);

        return $this->response($projectId);
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function create(Employee $actor, Request $request): array
    {
        if (! PortalRules::holds($actor, $request, PortalProjectPowers::CREATE)) {
            abort(403, 'Adding a project needs the Add projects power.');
        }

        $fields = $this->validatedFields($request, null);
        if (! PortalProjectPowers::reachesDepartment(PortalRules::reach($actor, $request, PortalProjectPowers::CREATE), $actor->department, $fields['columns']['department'])) {
            abort(403, 'Your Add projects power does not reach that department.');
        }
        $scopeCodes = $this->validatedScopeCodes($request, $fields['columns']['department']);
        $employeeId = (int) $actor->getKey();
        // Only Auto-approve passes a new project; approving others' is not approving your own (2026-10-07).
        $isApproved = PortalRules::holds($actor, $request, PortalProjectPowers::DIRECT);

        $numbered = null;
        $projectId = $this->ledger->within($this->connection(), function () use ($fields, $scopeCodes, $actor, $employeeId, $request, $isApproved, &$numbered): int {
            // The client's row is the lock that keeps two projects from taking one number.
            $client = $this->connection()->table('clients')->where('id', $fields['clientId'])->lockForUpdate()->first(['id', 'client_id']);
            if ($client === null) {
                abort(422, 'Choose a client from the list.');
            }
            $this->assertNameFree($fields['name'], null);
            $number = $this->nextNumber($client);
            $numbered = $number.' '.$fields['name'];
            $projectId = (int) $this->connection()->table('projects')->insertGetId([
                ...$fields['columns'],
                'project_number' => $number,
                'project_name' => $numbered,
                'project_creation_status' => $isApproved ? PortalProjectApproval::APPROVED : PortalProjectApproval::PENDING,
                'project_creation_approver' => $isApproved ? $this->personName($actor) : null,
                'project_approver_email' => $isApproved ? $this->text($actor->email ?? null) : null,
                'requested_by' => $employeeId,
                'requested_at' => now(),
            ]);
            $this->replacePeople($projectId, self::MEMBER_ROLE, $fields['memberIds']);
            $this->replacePeople($projectId, self::SUPPORT_ROLE, $fields['supportMemberIds']);
            $this->replaceClient($projectId, $fields['clientId']);
            $this->writeScopes($projectId, $scopeCodes);
            $this->repositories->replace($projectId, $fields['repositories']);

            $this->ledger->recordAdd(
                CoreLedger::PRODUCT_PORTAL,
                CoreRecycleKey::project($projectId),
                self::CORE_TARGET,
                [...$fields['ledger'], 'id' => (string) $projectId, 'scopeCodes' => $scopeCodes],
                self::CORE_RESOURCE,
                (string) $projectId,
                $employeeId,
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            $this->trail->record($projectId, PortalProjectTrail::CREATED, $actor, $request);

            return $projectId;
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            self::CORE_RESOURCE,
            PortalActivityCopy::addedProject((string) $numbered),
            (string) $projectId,
            $request,
            ['projectName' => $numbered, 'department' => $fields['columns']['department']],
        );
        if (! $isApproved) {
            $this->approval->notifyManagers($actor, $request, $this->liveProjectOrFail($projectId), (string) $numbered, false);
        }
        $this->projects->bumpCache();

        return $this->response($projectId);
    }

    /**
     * Saving a Rejected project sends it again for approval, unless its editor auto-approves.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $projectId): array
    {
        $row = $this->liveProjectOrFail($projectId);
        $this->assertMay($actor, $row, $request, PortalProjectPowers::EDIT);

        $fields = $this->validatedFields($request, $projectId);
        [$number] = self::split($row);
        $fields['columns']['project_name'] = $number === null ? $fields['name'] : $number.' '.$fields['name'];
        $changes = $this->changes($projectId, $fields);
        $isResubmitted = $row->project_creation_status === PortalProjectApproval::REJECTED
            && ! PortalRules::holds($actor, $request, PortalProjectPowers::DIRECT);
        $columns = $isResubmitted ? [
            ...$fields['columns'],
            'project_creation_status' => PortalProjectApproval::PENDING,
            'project_creation_approver' => null,
            'project_approver_email' => null,
            'project_creation_approver_remarks' => null,
        ] : $fields['columns'];

        $this->ledger->within($this->connection(), function () use ($fields, $columns, $projectId, $actor, $changes, $request, $isResubmitted): void {
            $this->assertNameFree($fields['name'], $projectId);
            $this->connection()->table('projects')->where('id', $projectId)->update($columns);
            $this->replacePeople($projectId, self::MEMBER_ROLE, $fields['memberIds']);
            $this->replacePeople($projectId, self::SUPPORT_ROLE, $fields['supportMemberIds']);
            $this->replaceClient($projectId, $fields['clientId']);
            $this->repositories->replace($projectId, $fields['repositories']);

            $this->ledger->recordEdit(
                CoreLedger::PRODUCT_PORTAL,
                self::CORE_TARGET,
                [...$fields['ledger'], 'id' => (string) $projectId],
                self::CORE_RESOURCE,
                (string) $projectId,
                CoreRecycleKey::project($projectId),
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            if ($changes !== null) {
                $this->trail->record($projectId, PortalProjectTrail::EDITED, $actor, $request, $changes);
            }
            if ($isResubmitted) {
                $this->trail->record($projectId, PortalProjectTrail::RESUBMITTED, $actor, $request);
            }
        });
        if ($isResubmitted) {
            $this->approval->notifyManagers($actor, $request, $this->liveProjectOrFail($projectId), $fields['name'], true);
        }

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::editedProject($fields['name']),
            (string) $projectId,
            $request,
            ['projectName' => $fields['name'], 'department' => $fields['columns']['department']],
        );
        $this->projects->bumpCache();

        return $this->response($projectId);
    }

    /**
     * Manage Scopes saves the whole set: what is not sent is no longer a scope of the project.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function replaceScopes(Employee $actor, Request $request, int $projectId): array
    {
        $row = $this->liveProjectOrFail($projectId);
        $this->assertMay($actor, $row, $request, PortalProjectPowers::SCOPES);

        $scopeCodes = $this->validatedScopeCodes($request, $row->department ?? null);
        $name = $this->text($row->project_name) ?? 'Project';

        $this->ledger->within($this->connection(), function () use ($scopeCodes, $projectId, $name, $actor, $request): void {
            $this->connection()->table('projects_wbs_scopes')->where('project_id', $projectId)->delete();
            $this->writeScopes($projectId, $scopeCodes);

            $this->ledger->recordEdit(
                CoreLedger::PRODUCT_PORTAL,
                self::CORE_TARGET,
                ['id' => (string) $projectId, 'projectName' => $name, 'scopeCodes' => $scopeCodes],
                self::CORE_RESOURCE,
                (string) $projectId,
                CoreRecycleKey::project($projectId),
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            $count = count($scopeCodes);
            $this->trail->record($projectId, PortalProjectTrail::SCOPES, $actor, $request, $count === 0
                ? 'Scopes cleared'
                : $count.' '.($count === 1 ? 'scope' : 'scopes'));
        });

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::changedProjectScopes($name, count($scopeCodes)),
            (string) $projectId,
            $request,
            ['projectName' => $name, 'scopeCount' => count($scopeCodes)],
        );
        $this->projects->bumpCache();

        return $this->response($projectId);
    }

    /**
     * @return array{
     *     name: string,
     *     clientId: int,
     *     memberIds: list<int>,
     *     supportMemberIds: list<int>,
     *     repositories: list<string>,
     *     columns: array<string, mixed>,
     *     ledger: array<string, mixed>
     * }
     */
    private function validatedFields(Request $request, ?int $projectId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'leadId' => ['required', 'integer'],
            'clientId' => ['required', 'integer'],
            'department' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'status' => ['required', 'string', Rule::in(array_keys(self::STATUSES))],
            'memberIds' => ['nullable', 'array', 'max:'.self::MAX_PEOPLE],
            'memberIds.*' => ['integer', 'distinct'],
            'supportMemberIds' => ['nullable', 'array', 'max:'.self::MAX_PEOPLE],
            'supportMemberIds.*' => ['integer', 'distinct'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['nullable', 'string', Rule::in(self::LEVELS)],
            'links' => ['nullable', 'array'],
            'links.*' => ['nullable', 'string', 'max:1000'],
            'repositories' => ['nullable', 'array', 'max:'.PortalProjectRepositories::MAX_PER_PROJECT],
            'repositories.*' => ['nullable', 'string', 'max:300'],
        ], [], [
            'leadId' => 'project lead',
            'clientId' => 'client',
            'type' => 'type of project',
            'memberIds' => 'members',
            'memberIds.*' => 'member',
            'supportMemberIds' => 'support members',
            'supportMemberIds.*' => 'support member',
            'levels.*' => 'level of detail',
            'links.*' => 'link',
            'repositories.*' => 'repository',
        ]);

        $name = $this->text(preg_replace('/\s+/u', ' ', (string) $data['name']));
        if ($name === null) {
            abort(422, 'A project name is required.');
        }
        if ($projectId === null && preg_match('/^\d{5,6}\s/', $name) === 1) {
            abort(422, 'Leave the project number out of the name; it is added when the project is saved.');
        }

        $department = $this->text($data['department']);
        if ($department === null || ! in_array($department, $this->departments(), true)) {
            abort(422, 'Choose a department from the list.');
        }
        $leadEmail = $this->validatedLeadEmail((int) $data['leadId'], $projectId, $department);
        $clientId = (int) $data['clientId'];
        if (! $this->connection()->table('clients')->where('id', $clientId)->exists()) {
            abort(422, 'Choose a client from the list.');
        }
        $type = $this->text($data['type'] ?? null);
        if ($type !== null && ! in_array($type, $this->jobTypes(), true)) {
            abort(422, 'Choose a type of project from the list.');
        }

        $memberIds = $this->validatedPeople($data['memberIds'] ?? [], $projectId, self::MEMBER_ROLE);
        $supportMemberIds = $this->validatedPeople($data['supportMemberIds'] ?? [], $projectId, self::SUPPORT_ROLE);
        $isEngineering = PortalProjectWork::isEngineering($department);
        $area = $isEngineering && isset($data['area']) ? round((float) $data['area'], 2) : null;
        $status = self::STATUSES[$data['status']];
        if ($isEngineering && ($area === null || $area <= 0)) {
            abort(422, 'An Engineering project needs its area in square feet, above 0.');
        }
        if ($isEngineering) {
            $missing = [];
            foreach (self::LEVEL_LABELS as $discipline => $label) {
                if ($this->text($data['levels'][$discipline] ?? null) === null) {
                    $missing[] = $label;
                }
            }
            if ($missing !== []) {
                abort(422, 'An Engineering project needs a level of detail for '.self::listed($missing).'.');
            }
        }
        $missingLinks = [];
        foreach ($isEngineering ? self::LINK_LABELS : [] as $kind => $label) {
            if ($this->text($data['links'][$kind] ?? null) === null) {
                $missingLinks[] = $label;
            }
        }
        if ($missingLinks !== []) {
            abort(422, 'An Engineering project needs its links. Add '.self::listed($missingLinks).'.');
        }

        $columns = [
            'project_name' => $name,
            'department' => $department,
            'type_of_job' => $type,
            'area_sqft' => $area,
            'status' => $status,
            'project_lead_email' => $leadEmail,
            'date_modified' => now(),
        ];
        $levels = $isEngineering && is_array($data['levels'] ?? null) ? $data['levels'] : [];
        foreach (self::LEVEL_COLUMNS as $discipline => $column) {
            $level = $this->text($levels[$discipline] ?? null);
            $columns[$column] = $level === null ? null : ($level === 'N/A' ? $level : 'LOD '.$level);
        }
        // Only MIS tracks code from Bitbucket; any other department's project keeps none.
        $repositories = PortalProjectRepositories::appliesTo($department) && is_array($data['repositories'] ?? null)
            ? PortalProjectRepositories::parse($data['repositories'])
            : [];
        $links = is_array($data['links'] ?? null) ? $data['links'] : [];
        foreach (self::LINK_COLUMNS as $kind => $column) {
            $columns[$column] = $isEngineering || ! in_array($kind, self::ENGINEERING_LINKS, true)
                ? $this->validatedLink($kind, $links[$kind] ?? null)
                : null;
        }

        return [
            'name' => $name,
            'clientId' => $clientId,
            'memberIds' => $memberIds,
            'supportMemberIds' => $supportMemberIds,
            'repositories' => $repositories,
            'columns' => $columns,
            'ledger' => [
                'projectName' => $name,
                'department' => $department,
                'typeOfJob' => $type,
                'areaSqft' => $area,
                'status' => $status,
                'leadId' => (string) (int) $data['leadId'],
                'clientId' => (string) $clientId,
                'memberIds' => array_map('strval', $memberIds),
                'supportMemberIds' => array_map('strval', $supportMemberIds),
                'repositories' => $repositories,
            ],
        ];
    }

    /**
     * What an edit changes, read before the write, as the Trail history row's remarks; null when
     * nothing changed. Plain values show old to new; people, levels and links only say they changed.
     *
     * @param  array{clientId: int, memberIds: list<int>, supportMemberIds: list<int>, repositories: list<string>, columns: array<string, mixed>}  $fields
     */
    private function changes(int $projectId, array $fields): ?string
    {
        $before = $this->connection()->table('projects')->where('id', $projectId)->first();
        if ($before === null) {
            return null;
        }
        $after = $fields['columns'];
        $parts = [];

        foreach (['project_name' => 'Name', 'department' => 'Department', 'type_of_job' => 'Type'] as $column => $label) {
            // A name is told apart from its number, which never changes.
            $old = $column === 'project_name' ? $this->text(self::split($before)[1]) : $this->text($before->{$column} ?? null);
            $new = $column === 'project_name' ? $this->text($fields['name']) : $this->text($after[$column] ?? null);
            if ($old !== $new) {
                $parts[] = $label.': '.($old ?? 'none').' to '.($new ?? 'none');
            }
        }
        $oldStatus = self::STATUS_LABELS[PortalViewProjectPresenter::status($before->status ?? null)];
        $newStatus = self::STATUS_LABELS[PortalViewProjectPresenter::status($after['status'] ?? null)];
        if ($oldStatus !== $newStatus) {
            $parts[] = 'Status: '.$oldStatus.' to '.$newStatus;
        }
        $oldArea = $before->area_sqft === null ? null : round((float) $before->area_sqft, 2);
        $newArea = $after['area_sqft'] ?? null;
        if ($oldArea !== $newArea) {
            $parts[] = 'Area: '.($oldArea === null ? 'none' : number_format($oldArea)).' to '.($newArea === null ? 'none' : number_format((float) $newArea)).' sq ft';
        }
        if (strcasecmp((string) $this->text($before->project_lead_email ?? null), (string) $after['project_lead_email']) !== 0) {
            $parts[] = 'Lead changed';
        }

        $clientIds = $this->connection()->table('projects_clients')->where('project_id', $projectId)->pluck('client_id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($clientIds !== [$fields['clientId']]) {
            $parts[] = 'Client changed';
        }
        foreach ([self::MEMBER_ROLE => ['memberIds', 'Members changed'], self::SUPPORT_ROLE => ['supportMemberIds', 'Support members changed']] as $role => [$key, $message]) {
            $old = $this->connection()->table('employees_projects')->where('project_id', $projectId)->where('role_on_project', $role)->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $new = $fields[$key];
            sort($new);
            if ($old !== $new) {
                $parts[] = $message;
            }
        }
        $oldRepositories = $this->repositories->forProject($projectId);
        $newRepositories = $fields['repositories'];
        sort($oldRepositories);
        sort($newRepositories);
        if ($oldRepositories !== $newRepositories) {
            $parts[] = 'Repositories changed';
        }
        foreach (['Levels of detail changed' => self::LEVEL_COLUMNS, 'Links changed' => self::LINK_COLUMNS] as $message => $columns) {
            foreach ($columns as $column) {
                if ($this->text($before->{$column} ?? null) !== $this->text($after[$column] ?? null)) {
                    $parts[] = $message;
                    break;
                }
            }
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    // An active employee with an email whose Project Lead reaches the department, or on an edit the lead the project already has.
    private function validatedLeadEmail(int $leadId, ?int $projectId, string $department): string
    {
        $lead = $this->connection()->table('employees')->where('id', $leadId)->first(['email', 'status', 'department']);
        $email = $lead === null ? null : $this->text($lead->email);
        if ($email === null) {
            abort(422, 'Choose a project lead from the list.');
        }

        $current = $projectId === null ? null : $this->text(
            $this->connection()->table('projects')->where('id', $projectId)->value('project_lead_email'),
        );
        if ($current !== null && strcasecmp($current, $email) === 0) {
            return $current;
        }
        if (strtolower(trim((string) $lead->status)) !== 'active') {
            abort(422, 'Choose a project lead from the list.');
        }
        if (! PortalProjectPowers::reachesDepartment(PortalProjectPowers::leadReaches()[$leadId] ?? null, $lead->department, $department)) {
            abort(422, 'That person cannot lead a project of this department. Choose a lead from the list.');
        }

        return $email;
    }

    /**
     * Active employees, plus on an edit whoever already holds that role on the project, so saving a
     * project never drops someone who has since left.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function validatedPeople(array $ids, ?int $projectId, string $role): array
    {
        $wanted = array_values(array_unique(array_map('intval', $ids)));
        if ($wanted === []) {
            return [];
        }

        $allowed = $this->connection()
            ->table('employees')
            ->whereIn('id', $wanted)
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($projectId !== null) {
            $current = $this->connection()
                ->table('employees_projects')
                ->where('project_id', $projectId)
                ->where('role_on_project', $role)
                ->whereIn('employee_id', $wanted)
                ->pluck('employee_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $allowed = [...$allowed, ...$current];
        }

        if (array_diff($wanted, $allowed) !== []) {
            abort(422, $role === self::SUPPORT_ROLE
                ? 'Choose support members from the list.'
                : 'Choose members from the list.');
        }

        return $wanted;
    }

    private function validatedLink(string $kind, mixed $value): ?string
    {
        $link = $this->text($value);
        if ($link === null || in_array($kind, self::FREE_TEXT_LINKS, true)) {
            return $link;
        }
        if (filter_var($link, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $link) !== 1) {
            abort(422, 'Each link must start with http:// or https://.');
        }

        return $link;
    }

    /**
     * WBS codes for an Engineering project; for any other, its department's and General's activity codes.
     *
     * @return list<string>
     */
    private function validatedScopeCodes(Request $request, mixed $department): array
    {
        $data = $request->validate([
            'scopeCodes' => ['nullable', 'array', 'max:'.self::MAX_SCOPES],
            'scopeCodes.*' => ['string', 'max:32'],
        ], [], ['scopeCodes' => 'scopes', 'scopeCodes.*' => 'scope']);

        $codes = [];
        foreach ($data['scopeCodes'] ?? [] as $raw) {
            $code = trim((string) $raw);
            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        $known = PortalProjectWork::isEngineering($department)
            ? $this->knownScopeCodes()
            : array_column(PortalActivityScopes::forDepartment(PortalActivityScopes::all(), $department), 'code');
        if (array_diff($codes, $known) !== []) {
            abort(422, 'Choose scopes from the list.');
        }

        return $codes;
    }

    /**
     * Every WBS code across the four tiers. Airtable holds one task code with its number missing
     * ('200-03-02-'), which the portal's catalogue reads as the first task, '-01'.
     *
     * @return list<string>
     */
    private function knownScopeCodes(): array
    {
        $codes = [];
        foreach (self::SCOPE_TABLES as $table) {
            foreach ($this->connection()->table($table)->pluck('wbs_code') as $raw) {
                $code = trim((string) $raw);
                if ($code !== '') {
                    $codes[] = str_ends_with($code, '-') ? $code.'01' : $code;
                }
            }
        }

        return $codes;
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function replacePeople(int $projectId, string $role, array $employeeIds): void
    {
        $this->connection()
            ->table('employees_projects')
            ->where('project_id', $projectId)
            ->where('role_on_project', $role)
            ->delete();
        if ($employeeIds === []) {
            return;
        }
        $this->connection()->table('employees_projects')->insert(array_map(
            static fn (int $employeeId): array => ['employee_id' => $employeeId, 'project_id' => $projectId, 'role_on_project' => $role],
            $employeeIds,
        ));
    }

    private function replaceClient(int $projectId, int $clientId): void
    {
        $this->connection()->table('projects_clients')->where('project_id', $projectId)->delete();
        $this->connection()->table('projects_clients')->insert(['project_id' => $projectId, 'client_id' => $clientId]);
    }

    /**
     * @param  list<string>  $codes
     */
    private function writeScopes(int $projectId, array $codes): void
    {
        if ($codes === []) {
            return;
        }
        $this->connection()->table('projects_wbs_scopes')->insert(array_map(
            static fn (string $code): array => ['project_id' => $projectId, 'wbs_code' => $code],
            $codes,
        ));
    }

    /**
     * The project as the form edits it: ids rather than names, so a rename never breaks a save.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    private function response(int $projectId): array
    {
        $row = $this->connection()->table('projects')->where('id', $projectId)->first();
        if ($row === null) {
            abort(404, 'That project was not found.');
        }

        $leadEmail = $this->text($row->project_lead_email ?? null);
        $lead = $leadEmail === null ? null : $this->connection()
            ->table('employees')
            ->whereRaw('LOWER(email) = ?', [strtolower($leadEmail)])
            ->orderBy('id')
            ->first(['id', 'first_name', 'last_name']);

        $people = [self::MEMBER_ROLE => [], self::SUPPORT_ROLE => []];
        $rows = $this->connection()
            ->table('employees_projects')
            ->join('employees', 'employees.id', '=', 'employees_projects.employee_id')
            ->where('employees_projects.project_id', $projectId)
            ->whereIn('employees_projects.role_on_project', [self::MEMBER_ROLE, self::SUPPORT_ROLE])
            ->orderBy('employees.first_name')
            ->orderBy('employees.last_name')
            ->get(['employees.id', 'employees.first_name', 'employees.last_name', 'employees_projects.role_on_project']);
        foreach ($rows as $person) {
            $people[(string) $person->role_on_project][] = ['id' => (string) (int) $person->id, 'name' => $this->personName($person)];
        }

        $clientId = $this->connection()->table('projects_clients')->where('project_id', $projectId)->orderBy('client_id')->value('client_id');
        $levels = [];
        foreach (self::LEVEL_COLUMNS as $discipline => $column) {
            $levels[$discipline] = $this->levelOf($row->{$column} ?? null);
        }
        $links = [];
        foreach (self::LINK_COLUMNS as $kind => $column) {
            $links[$kind] = $this->text($row->{$column} ?? null) ?? '';
        }
        $area = $row->area_sqft ?? null;
        [$number, $name] = self::split($row);

        return [
            'section' => 'projects',
            'resource' => 'project',
            'data' => [
                'id' => (string) $projectId,
                'number' => $number,
                'name' => $name,
                'leadId' => $lead === null ? null : (string) (int) $lead->id,
                'leadName' => $lead === null ? null : $this->personName($lead),
                'clientId' => $clientId === null ? null : (string) (int) $clientId,
                'department' => $this->text($row->department ?? null),
                'type' => $this->text($row->type_of_job ?? null),
                'area' => $area === null || $area === '' ? null : (float) $area,
                'status' => PortalViewProjectPresenter::status($row->status ?? null),
                'creationStatus' => PortalViewProjectPresenter::creationStatus($row->project_creation_status ?? null),
                'creationRemarks' => $this->text($row->project_creation_approver_remarks ?? null),
                'members' => $people[self::MEMBER_ROLE],
                'supportMembers' => $people[self::SUPPORT_ROLE],
                'levels' => $levels,
                'links' => $links,
                'repositories' => $this->repositories->forProject($projectId),
                'scopeCodes' => $this->projects->scopeCodesForProject($projectId),
            ],
        ];
    }

    // 'LOD 300' reads back as '300'; anything the form has no option for reads as none.
    private function levelOf(mixed $stored): ?string
    {
        $value = $this->text($stored);
        if ($value === null) {
            return null;
        }
        $level = preg_replace('/^LOD\s*/i', '', $value) ?? $value;

        return in_array($level, self::LEVELS, true) ? $level : null;
    }

    // Edit projects and Manage scopes reach this project, or the actor asked for it and it is not approved yet.
    private function assertMay(Employee $actor, object $row, Request $request, string $key): void
    {
        if (! PortalProjectPowers::allows($actor, $request, $key, $row)) {
            abort(403, $key === PortalProjectPowers::SCOPES
                ? 'Your Manage scopes power does not reach this project.'
                : 'Your Edit projects power does not reach this project.');
        }
    }

    private function leadsAnyProject(Employee $actor): bool
    {
        $own = $this->text($actor->email ?? null);

        return $own !== null && $this->connection()
            ->table('projects')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(project_lead_email) = ?', [strtolower($own)])
            ->exists();
    }

    private function liveProjectOrFail(int $projectId): object
    {
        $row = $this->connection()
            ->table('projects')
            ->where('id', $projectId)
            ->whereNull('deleted_at')
            ->first(['id', 'project_number', 'project_name', 'department', 'project_lead_email', 'project_creation_status', 'requested_by']);
        if ($row === null) {
            abort(404, 'That project was not found.');
        }

        return $row;
    }

    /**
     * The project's number and its name without it: project_number when there is one, else a number
     * leading the name ("260134 JMH PH2"), else none.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function split(object $row): array
    {
        $name = trim((string) ($row->project_name ?? ''));
        $number = trim((string) ($row->project_number ?? ''));
        if (preg_match('/^(\d+)\s+(.+)$/', $name, $match) === 1 && ($number === '' || $number === $match[1])) {
            return [$match[1], trim($match[2])];
        }

        return [$number === '' ? null : $number, $name];
    }

    // Names are compared without their number, case or extra spaces, among live projects.
    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $key = self::nameKey($name);
        $rows = $this->connection()
            ->table('projects')
            ->whereNull('deleted_at')
            ->where('project_name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name).'%')
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '<>', $exceptId))
            ->lockForUpdate()
            ->get(['id', 'project_number', 'project_name']);
        foreach ($rows as $row) {
            [, $other] = self::split($row);
            if (self::nameKey($other) === $key) {
                abort(422, 'A project named "'.$name.'" already exists ('.trim((string) $row->project_name).'). Choose another name.');
            }
        }
    }

    private function nextNumber(object $client): string
    {
        $code = self::clientCode($client->client_id);
        if ($code === null) {
            abort(422, 'That client has no two-digit client code, so the project cannot be numbered. Give the client a code first.');
        }
        $year = $this->year();
        $next = ($this->usedCounters($year)[$code] ?? 0) + 1;
        if ($next > 99) {
            abort(422, 'This client has used all 99 project numbers for 20'.$year.'.');
        }

        return $year.$code.sprintf('%02d', $next);
    }

    /**
     * The highest counter each client code has used this year, deleted projects included.
     *
     * @return array<string, int>
     */
    private function usedCounters(string $year): array
    {
        $rows = $this->connection()
            ->table('projects')
            ->where(static function ($query) use ($year): void {
                $query->where('project_number', 'like', $year.'%')->orWhere('project_name', 'like', $year.'%');
            })
            ->get(['project_number', 'project_name']);
        $used = [];
        foreach ($rows as $row) {
            foreach ([(string) ($row->project_number ?? ''), (string) ($row->project_name ?? '')] as $text) {
                if (preg_match('/^'.$year.'(\d{2})(\d{2})(?:\s|$)/', trim($text), $match) === 1) {
                    $used[$match[1]] = max($used[$match[1]] ?? 0, (int) $match[2]);
                }
            }
        }

        return $used;
    }

    private function year(): string
    {
        return Carbon::now(self::COMPANY_ZONE)->format('y');
    }

    private static function clientCode(mixed $value): ?string
    {
        $code = trim((string) $value);

        return preg_match('/^\d{2}$/', $code) === 1 ? $code : null;
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param  list<string>  $items
     */
    private static function listed(array $items): string
    {
        return count($items) === 1
            ? $items[0]
            : implode(', ', array_slice($items, 0, -1)).' and '.$items[count($items) - 1];
    }

    /**
     * @return iterable<object>
     */
    private function activeEmployees(): iterable
    {
        return $this->connection()
            ->table('employees')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            // Executives are not put on projects: not a lead, a member or a support member.
            ->whereRaw("LOWER(COALESCE(role_level, '')) <> 'executive'")
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department']);
    }

    /**
     * The departments the form offers: those the company staffs plus any a live project is filed
     * under, so older buckets such as Admin and General stay available.
     *
     * @return list<string>
     */
    private function departments(): array
    {
        $names = [
            ...$this->connection()->table('projects')->whereNull('deleted_at')->distinct()->pluck('department')->all(),
            ...$this->connection()->table('employees')->distinct()->pluck('department')->all(),
            // What Manage / Project Settings adds, so a department added there is offered.
            ...$this->connection()->table('departments')->distinct()->pluck('name')->all(),
        ];

        return $this->distinctSorted($names);
    }

    /**
     * @return list<string>
     */
    private function jobTypes(): array
    {
        $names = [
            ...$this->connection()->table('projects')->distinct()->pluck('type_of_job')->all(),
            // What Manage / Project Settings adds, so a job type added there is offered.
            ...$this->connection()->table('job_types')->distinct()->pluck('name')->all(),
        ];

        return $this->distinctSorted($names);
    }

    /**
     * Job types grouped by the department that uses them, so the form offers only the chosen one's.
     *
     * @return array<string, list<string>>
     */
    private function typesByDepartment(): array
    {
        $rows = $this->connection()
            ->table('projects')
            ->get(['department', 'type_of_job']);

        $types = [];
        foreach ($rows as $row) {
            $department = $this->text($row->department);
            $type = $this->text($row->type_of_job);
            if ($department === null || $type === null) {
                continue;
            }
            $types[$department] ??= [];
            if (! in_array($type, $types[$department], true)) {
                $types[$department][] = $type;
            }
        }
        // Job types added on Manage / Project Settings belong to the department that owns them.
        foreach ($this->connection()->table('job_types')->get(['department', 'name']) as $row) {
            $department = $this->text($row->department);
            $type = $this->text($row->name);
            if ($department === null || $type === null) {
                continue;
            }
            $types[$department] ??= [];
            if (! in_array($type, $types[$department], true)) {
                $types[$department][] = $type;
            }
        }
        foreach ($types as $department => $list) {
            $types[$department] = $this->distinctSorted($list);
        }

        return $types;
    }

    /**
     * The activity codes each job type may use on a report, keyed by job type. A type with no rule is
     * absent, and the report falls back to the whole catalogue for it. Stored prefixes are resolved to
     * whole codes here, so the form shows ticked codes rather than their digits.
     *
     * @return array<string, list<string>>
     */
    private function allowedActivitiesByType(): array
    {
        $codes = PortalActivityScopes::all();
        $rows = $this->connection()->table('job_type_allowed_activities')->get(['job_type', 'activity_code_id_initials']);

        $allowed = [];
        foreach ($rows as $row) {
            $jobType = $this->text($row->job_type);
            if ($jobType === null) {
                continue;
            }
            $initials = [];
            foreach (explode(',', (string) ($row->activity_code_id_initials ?? '')) as $piece) {
                $value = trim($piece);
                if ($value !== '') {
                    $initials[] = $value;
                }
            }
            $chosen = [];
            foreach ($codes as $code) {
                foreach ($initials as $prefix) {
                    if ($code['code'] !== '' && str_starts_with($code['code'], $prefix)) {
                        $chosen[] = $code['code'];
                        break;
                    }
                }
            }
            $allowed[$jobType] = $chosen;
        }

        return $allowed;
    }

    /**
     * Set which activity codes a job type may use. Writes one row, the chosen codes comma-joined; the
     * report matches them as prefixes, so a stored code allows exactly itself. All reach only.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function saveAllowedActivities(Employee $actor, Request $request): array
    {
        if (PortalRules::reach($actor, $request, PortalProjectPowers::EDIT) !== 'all') {
            abort(403, 'Editing a project type needs Edit projects at every department.');
        }
        $data = $request->validate([
            'jobType' => ['required', 'string', 'max:191'],
            'activityCodes' => ['present', 'array', 'max:'.self::MAX_SCOPES],
            'activityCodes.*' => ['string', 'max:32'],
        ]);
        $jobType = trim($data['jobType']);
        if ($jobType === '' || ! in_array($jobType, $this->jobTypes(), true)) {
            abort(422, 'Choose a project type from the list the form offers.');
        }

        $known = [];
        foreach (PortalActivityScopes::all() as $code) {
            $known[$code['code']] = true;
        }
        $codes = [];
        foreach ($data['activityCodes'] as $raw) {
            $code = trim((string) $raw);
            if ($code !== '' && isset($known[$code]) && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $this->connection()->table('job_type_allowed_activities')->where('job_type', $jobType)->delete();
        if ($codes !== []) {
            $this->connection()->table('job_type_allowed_activities')->insert([
                'job_type' => $jobType,
                'activity_code_id_initials' => implode(',', $codes),
            ]);
        }
        // The report builder reads this table through its own cache; bump it so the form sees the change.
        (new PortalReportProjects)->bumpCache();

        return [
            'section' => 'projects',
            'resource' => 'allowed-activities',
            'data' => ['jobType' => $jobType, 'activityCodes' => $codes],
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function distinctSorted(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
            $name = $this->text($value);
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
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
