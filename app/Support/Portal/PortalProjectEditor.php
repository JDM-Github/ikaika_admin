<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
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

    private const LEVEL_COLUMNS = [
        'structural' => 'st_lod',
        'architectural' => 'ar_lod',
        'electrical' => 'el_lod',
        'plumbing' => 'pl_lod',
        'mechanical-duct' => 'md_lod',
        'fire-protection' => 'fp_lod',
    ];

    private const LINK_COLUMNS = [
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

    private const MAX_PEOPLE = 200;

    private const MAX_SCOPES = 300;

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalViewProjects $projects,
        private readonly PortalAudit $audit,
        private readonly PortalProjectTrail $trail,
        private readonly PortalProjectApproval $approval,
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

        $clients = [];
        foreach ($this->connection()->table('clients')->orderBy('name')->get(['id', 'name']) as $row) {
            $name = $this->text($row->name);
            if ($name !== null) {
                $clients[] = ['id' => (string) (int) $row->id, 'name' => $name];
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
        $isApproved = $this->approval->covers($actor, $request, $fields['columns']['department'])
            || PortalRules::holds($actor, $request, PortalProjectPowers::DIRECT);

        $projectId = $this->ledger->within($this->connection(), function () use ($fields, $scopeCodes, $actor, $employeeId, $request, $isApproved): int {
            $projectId = (int) $this->connection()->table('projects')->insertGetId([
                ...$fields['columns'],
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
            PortalActivityCopy::addedProject($fields['name']),
            (string) $projectId,
            $request,
            ['projectName' => $fields['name'], 'department' => $fields['columns']['department']],
        );
        if (! $isApproved) {
            $this->approval->notifyManagers($actor, $request, $projectId, $fields['name'], $fields['columns']['department'], false);
        }
        $this->projects->bumpCache();

        return $this->response($projectId);
    }

    /**
     * Saving a Rejected project sends it again for approval, unless Project Manager covers its adder.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $projectId): array
    {
        $row = $this->liveProjectOrFail($projectId);
        $this->assertMay($actor, $row, $request, PortalProjectPowers::EDIT);

        $fields = $this->validatedFields($request, $projectId);
        $changes = $this->changes($projectId, $fields);
        $isResubmitted = $row->project_creation_status === PortalProjectApproval::REJECTED
            && ! $this->approval->covers($actor, $request, $fields['columns']['department']);
        $columns = $isResubmitted ? [
            ...$fields['columns'],
            'project_creation_status' => PortalProjectApproval::PENDING,
            'project_creation_approver' => null,
            'project_approver_email' => null,
            'project_creation_approver_remarks' => null,
        ] : $fields['columns'];

        $this->ledger->within($this->connection(), function () use ($fields, $columns, $projectId, $actor, $changes, $request, $isResubmitted): void {
            $this->connection()->table('projects')->where('id', $projectId)->update($columns);
            $this->replacePeople($projectId, self::MEMBER_ROLE, $fields['memberIds']);
            $this->replacePeople($projectId, self::SUPPORT_ROLE, $fields['supportMemberIds']);
            $this->replaceClient($projectId, $fields['clientId']);

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
            $this->approval->notifyManagers($actor, $request, $projectId, $fields['name'], $fields['columns']['department'], true);
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
        ]);

        $name = $this->text($data['name']);
        if ($name === null) {
            abort(422, 'A project name is required.');
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
            ],
        ];
    }

    /**
     * What an edit changes, read before the write, as the Trail history row's remarks; null when
     * nothing changed. Plain values show old to new; people, levels and links only say they changed.
     *
     * @param  array{clientId: int, memberIds: list<int>, supportMemberIds: list<int>, columns: array<string, mixed>}  $fields
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
            $old = $this->text($before->{$column} ?? null);
            $new = $this->text($after[$column] ?? null);
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

        return [
            'section' => 'projects',
            'resource' => 'project',
            'data' => [
                'id' => (string) $projectId,
                'name' => $this->text($row->project_name ?? null) ?? '',
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
            ->first(['id', 'project_name', 'department', 'project_lead_email', 'project_creation_status', 'requested_by']);
        if ($row === null) {
            abort(404, 'That project was not found.');
        }

        return $row;
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
     * The departments the company actually staffs, taken from its employees. Retired buckets
     * projects were once filed under (Admin, General) are not offered on the form.
     *
     * @return list<string>
     */
    private function departments(): array
    {
        return $this->distinctSorted(
            $this->connection()->table('employees')->distinct()->pluck('department')->all(),
        );
    }

    /**
     * @return list<string>
     */
    private function jobTypes(): array
    {
        return $this->distinctSorted($this->connection()->table('projects')->distinct()->pluck('type_of_job')->all());
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
        foreach ($types as $department => $list) {
            $types[$department] = $this->distinctSorted($list);
        }

        return $types;
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
