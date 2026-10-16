<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Manage / Project Settings: the reference data a project is filed under, all editable here.
 *
 * A scope is an activity code a non-Engineering department reports on (activity_codes). A job type is
 * a type of project a department uses (job_types). A department is a bucket a project is filed under
 * (departments). The Add/Edit Project form offers these lists unioned with what live projects and
 * employees already use, so adding one here shows up and an old value is never lost.
 *
 * A client is who a project is for (clients), with the two-digit code its project numbers carry
 * (260202: 2026, client 02). The code is fixed once a project has used it, and a client with
 * projects is not removed, since its numbers would then point at nobody.
 */
final class PortalReferences
{
    public function __construct(
        private readonly PortalAudit $audit,
        private readonly PortalScopeTree $scopeTree,
        private readonly CoreLedger $ledger,
    ) {}

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function index(): array
    {
        $scopes = [];
        foreach ($this->connection()->table('activity_codes')->orderBy('department')->orderBy('id_no')->get(['id', 'id_no', 'name', 'department']) as $row) {
            $scopes[] = [
                'id' => (string) (int) $row->id,
                'code' => trim((string) $row->id_no),
                'name' => trim((string) $row->name),
                'department' => trim((string) $row->department),
            ];
        }

        $jobTypes = [];
        foreach ($this->connection()->table('job_types')->orderBy('department')->orderBy('name')->get(['id', 'department', 'name']) as $row) {
            $jobTypes[] = [
                'id' => (string) (int) $row->id,
                'department' => trim((string) $row->department),
                'name' => trim((string) $row->name),
            ];
        }

        // The company's leave vocabulary, the list the leave form offers.
        $leaveTypes = [];
        foreach ($this->connection()->table('leave_types')->orderBy('name')->get(['id', 'name']) as $row) {
            $leaveTypes[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->name),
            ];
        }

        // The offices a reimbursement line may belong to, the list it offers.
        $teams = [];
        foreach ($this->connection()->table('teams')->orderBy('name')->get(['id', 'name']) as $row) {
            $teams[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->name),
            ];
        }

        // Who staffs each department, for the Members column and its filter: active employees only,
        // matched the way every other department filter matches (case-insensitive, trimmed).
        $membersByDepartment = [];
        foreach ($this->connection()->table('employees')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('department')
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['department', 'first_name', 'last_name']) as $row) {
            $key = self::departmentKey((string) $row->department);
            $name = trim((string) $row->first_name.' '.(string) $row->last_name);
            if ($key === '' || $name === '') {
                continue;
            }
            $membersByDepartment[$key][] = $name;
        }

        $departments = [];
        foreach ($this->connection()->table('departments')->orderBy('name')->get(['id', 'name', 'description']) as $row) {
            $description = trim((string) $row->description);
            $departments[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->name),
                'description' => $description === '' ? null : $description,
                'members' => $membersByDepartment[self::departmentKey((string) $row->name)] ?? [],
            ];
        }

        $projectCounts = $this->connection()->table('projects_clients')
            ->join('projects', 'projects.id', '=', 'projects_clients.project_id')
            ->groupBy('projects_clients.client_id')
            ->selectRaw('projects_clients.client_id, COUNT(*) as total')
            ->pluck('total', 'client_id');
        $clients = [];
        foreach ($this->connection()->table('clients')->orderBy('client_id')->orderBy('name')->get(['id', 'name', 'client_id']) as $row) {
            $clients[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->name),
                'code' => self::clientCode($row->client_id),
                'projects' => (int) ($projectCounts[$row->id] ?? 0),
            ];
        }

        return [
            'section' => 'manage',
            'resource' => 'references',
            'data' => [
                'scopes' => $scopes,
                'scopeTree' => $this->scopeTree->roots(),
                'jobTypes' => $jobTypes,
                'leaveTypes' => $leaveTypes,
                'teams' => $teams,
                'departments' => $departments,
                'clients' => $clients,
                'nextClientCode' => $this->nextClientCode(),
            ],
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addClient(Employee $actor, Request $request): array
    {
        [$name, $code] = $this->validatedClient($request, null);

        $id = $this->ledgered($actor, 'references.clients', 'clients', null, fn (): int => (int) $this->connection()->table('clients')->insertGetId(['name' => $name, 'client_id' => $code]), ['name' => $name, 'client_id' => $code]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.client', '{UserName|You} added the client '.$name.' ('.$code.')', (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateClient(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('clients')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That client was not found.');
        }
        [$name, $code] = $this->validatedClient($request, $id);
        if ($code !== self::clientCode($row->client_id) && $this->projectCount($id) > 0) {
            abort(422, 'This client\'s code is already in its project numbers, so it cannot change.');
        }

        $this->ledgered($actor, 'references.clients', 'clients', $row, fn (): int => $this->connection()->table('clients')->where('id', $id)->update(['name' => $name, 'client_id' => $code]), ['name' => $name, 'client_id' => $code]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.client', '{UserName|You} changed the client '.$name.' ('.$code.')', (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeClient(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('clients')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That client was not found.');
        }
        if ($this->projectCount($id) > 0) {
            abort(422, 'This client has projects, so it stays on the list.');
        }

        $this->ledgeredDelete($actor, 'references.clients', 'clients', $row);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.client', '{UserName|You} removed the client '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addScope(Employee $actor, Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:100'],
        ]);
        $code = trim($data['code']);
        if ($code === '') {
            abort(422, 'Give the scope a code.');
        }
        if ($this->codeTaken($code)) {
            abort(422, 'That scope code is already on the list.');
        }

        $columns = ['id_no' => $code, 'name' => trim($data['name']), 'department' => trim($data['department'])];
        $id = $this->ledgered($actor, 'references.activity_codes', 'activity_codes', null, fn (): int => (int) $this->connection()->table('activity_codes')->insertGetId($columns), $columns);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.scope', '{UserName|You} added the scope '.$code, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateScope(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('activity_codes')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That scope was not found.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:100'],
        ]);
        $code = trim($data['code']);
        if ($code === '') {
            abort(422, 'Give the scope a code.');
        }
        if ($this->codeTaken($code, $id)) {
            abort(422, 'That scope code is already on the list.');
        }

        $columns = ['id_no' => $code, 'name' => trim($data['name']), 'department' => trim($data['department'])];
        $this->ledgered($actor, 'references.activity_codes', 'activity_codes', $row, fn (): int => $this->connection()->table('activity_codes')->where('id', $id)->update($columns), $columns);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.scope', '{UserName|You} changed the scope '.$code, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeScope(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('activity_codes')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That scope was not found.');
        }

        $this->ledgeredDelete($actor, 'references.activity_codes', 'activity_codes', $row);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.scope', '{UserName|You} removed the scope '.trim((string) $row->id_no), (string) $id, $request);

        return $this->index();
    }

    /**
     * A scope in a department's tree: its code typed in the WBS shape, under a parent of the same department.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addTreeScope(Employee $actor, Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:100'],
        ]);
        [$code, $name] = [trim($data['code']), trim($data['name'])];
        if ($name === '') {
            abort(422, 'Give the scope a name.');
        }
        $department = $this->knownDepartment($data['department']);
        $this->treeLedgered($actor, $code, fn () => $this->scopeTree->add($department, $code, $name), 'add', ['wbs_code' => $code, 'name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.wbs', '{UserName|You} added the scope '.$code.' '.$name, $code, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function renameTreeScope(Employee $actor, Request $request, string $code): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = trim($data['name']);
        if ($name === '') {
            abort(422, 'Give the scope a name.');
        }
        $this->treeLedgered($actor, $code, fn () => $this->scopeTree->rename($code, $name), 'edit', [str_ends_with(PortalScopeTree::tableFor($code)[0], 't4_tasks') ? 'task_name' : 'name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.wbs', '{UserName|You} renamed the scope '.$code.' to '.$name, $code, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeTreeScope(Employee $actor, Request $request, string $code): array
    {
        $this->treeLedgered($actor, $code, fn () => $this->scopeTree->remove($code), 'delete', []);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.wbs', '{UserName|You} removed the scope '.$code, $code, $request);

        return $this->index();
    }

    // A department on the Departments tab, spelled as it is there.
    private function knownDepartment(string $raw): string
    {
        $key = self::departmentKey($raw);
        foreach ($this->connection()->table('departments')->pluck('name') as $name) {
            if (self::departmentKey((string) $name) === $key) {
                return trim((string) $name);
            }
        }
        abort(422, 'Choose a department from the list.');
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addJobType(Employee $actor, Request $request): array
    {
        $data = $request->validate([
            'department' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);
        $department = trim($data['department']);
        if ($name === '') {
            abort(422, 'Give the project type a name.');
        }
        if ($this->jobTypeTaken($department, $name)) {
            abort(422, 'That project type is already on the list for this department.');
        }

        $id = (int) $this->connection()->table('job_types')->insertGetId(['department' => $department, 'name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.type', '{UserName|You} added the project type '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section, resource, data}
     */
    public function updateJobType(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('job_types')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That project type was not found.');
        }
        $data = $request->validate([
            'department' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);
        $department = trim($data['department']);
        if ($name === '') {
            abort(422, 'Give the project type a name.');
        }
        if ($this->jobTypeTaken($department, $name, $id)) {
            abort(422, 'That project type is already on the list for this department.');
        }

        $oldName = trim((string) $row->name);
        $this->ledger->within($this->connection(), function () use ($id, $department, $name, $oldName, $row, $actor): void {
            $this->connection()->table('job_types')->where('id', $id)->update(['department' => $department, 'name' => $name]);
            // The report builder keys a type's allowed activities by name, so a rename carries them over: this
            // department's own row, and the shared row while no other department still uses the old name.
            if ($oldName === $name) {
                return;
            }
            $renamed = $this->connection()->table('job_type_allowed_activities')->where('job_type', $oldName)->where('department', trim((string) $row->department))->pluck('id')->all();
            if (! $this->connection()->table('job_types')->where('name', $oldName)->exists()) {
                $renamed = [...$renamed, ...$this->connection()->table('job_type_allowed_activities')->where('job_type', $oldName)->whereNull('department')->pluck('id')->all()];
            }
            $this->connection()->table('job_type_allowed_activities')->whereIn('id', $renamed)->update(['job_type' => $name]);
            foreach ($renamed as $allowedId) {
                $this->ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, CoreLedger::PRODUCT_PORTAL.'.job_type_allowed_activities', ['id' => (string) $allowedId, 'fields' => ['job_type' => $name]], 'references.allowed_activities', (string) $allowedId, null, (int) $actor->getKey(), is_string($actor->id_no) ? $actor->id_no : null);
            }
        });
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.type', '{UserName|You} changed the project type '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeJobType(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('job_types')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That project type was not found.');
        }

        $this->connection()->table('job_types')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.type', '{UserName|You} removed the project type '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * The company's leave vocabulary: the list the leave form offers, so a change here is what the
     * form offers next. A leave type carries no department, code or project count.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addLeaveType(Employee $actor, Request $request): array
    {
        $name = $this->validatedLeaveType($request);
        if ($this->leaveTypeTaken($name)) {
            abort(422, 'That leave type is already on the list.');
        }

        $id = (int) $this->connection()->table('leave_types')->insertGetId(['name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.leave-type', '{UserName|You} added the leave type '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateLeaveType(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('leave_types')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That leave type was not found.');
        }
        $name = $this->validatedLeaveType($request);
        if ($this->leaveTypeTaken($name, $id)) {
            abort(422, 'That leave type is already on the list.');
        }

        $this->connection()->table('leave_types')->where('id', $id)->update(['name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.leave-type', '{UserName|You} changed the leave type '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeLeaveType(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('leave_types')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That leave type was not found.');
        }

        $this->connection()->table('leave_types')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.leave-type', '{UserName|You} removed the leave type '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * The offices a reimbursement line may belong to. Like a leave type, a team is a bare name.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addTeam(Employee $actor, Request $request): array
    {
        $name = $this->validatedTeam($request);
        if ($this->teamTaken($name)) {
            abort(422, 'That team is already on the list.');
        }

        $id = (int) $this->connection()->table('teams')->insertGetId(['name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.team', '{UserName|You} added the team '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateTeam(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('teams')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That team was not found.');
        }
        $name = $this->validatedTeam($request);
        if ($this->teamTaken($name, $id)) {
            abort(422, 'That team is already on the list.');
        }

        $this->connection()->table('teams')->where('id', $id)->update(['name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.team', '{UserName|You} changed the team '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeTeam(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('teams')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That team was not found.');
        }

        $this->connection()->table('teams')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.team', '{UserName|You} removed the team '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addDepartment(Employee $actor, Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            abort(422, 'Give the department a name.');
        }
        if ($this->departmentTaken($name)) {
            abort(422, 'That department is already on the list.');
        }

        $id = (int) $this->connection()->table('departments')->insertGetId([
            'name' => $name,
            'description' => self::cleanDescription($data['description'] ?? null),
        ]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.references.department', '{UserName|You} added the department '.$name, (string) $id, $request);

        return $this->index();
    }

    /**
     * A department is renamed nowhere: projects, people, types and scopes are filed under its name,
     * so only the description changes here.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateDepartment(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('departments')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That department was not found.');
        }
        $data = $request->validate(['description' => ['nullable', 'string', 'max:255']]);

        $this->connection()->table('departments')->where('id', $id)->update([
            'description' => self::cleanDescription($data['description'] ?? null),
        ]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.references.department', '{UserName|You} changed the department '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeDepartment(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('departments')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That department was not found.');
        }

        $this->connection()->table('departments')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.references.department', '{UserName|You} removed the department '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * A client's name, unique on the list, and its two-digit code, unique too.
     *
     * @return array{0: string, 1: string}
     */
    private function validatedClient(Request $request, ?int $exceptId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10'],
        ]);
        $name = trim((string) preg_replace('/\s+/u', ' ', $data['name']));
        if ($name === '') {
            abort(422, 'Give the client a name.');
        }
        $code = self::clientCode($data['code']);
        if ($code === null) {
            abort(422, 'A client code is two digits, like 07.');
        }
        if ($this->taken('clients', 'name', $name, $exceptId)) {
            abort(422, 'That client is already on the list.');
        }
        if ($this->taken('clients', 'client_id', $code, $exceptId)) {
            abort(422, 'Another client already has the code '.$code.'.');
        }

        return [$name, $code];
    }

    // The lowest two-digit code no client has, for the Add client form to start from.
    private function nextClientCode(): ?string
    {
        $used = [];
        foreach ($this->connection()->table('clients')->pluck('client_id') as $value) {
            $code = self::clientCode($value);
            if ($code !== null) {
                $used[$code] = true;
            }
        }
        for ($next = 0; $next <= 99; $next++) {
            $code = sprintf('%02d', $next);
            if (! isset($used[$code])) {
                return $code;
            }
        }

        return null;
    }

    private function projectCount(int $clientId): int
    {
        return $this->connection()->table('projects_clients')->where('client_id', $clientId)->count();
    }

    private static function clientCode(mixed $value): ?string
    {
        $code = trim((string) $value);

        return preg_match('/^\d{2}$/', $code) === 1 ? $code : null;
    }

    private function codeTaken(string $code, ?int $exceptId = null): bool
    {
        return $this->taken('activity_codes', 'id_no', $code, $exceptId);
    }

    // A leave type's name, trimmed and non-empty, from the request the form sent.
    private function validatedLeaveType(Request $request): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim($data['name']);
        if ($name === '') {
            abort(422, 'Give the leave type a name.');
        }

        return $name;
    }

    private function leaveTypeTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->taken('leave_types', 'name', $name, $exceptId);
    }

    // A team's name, trimmed and non-empty, from the request the form sent.
    private function validatedTeam(Request $request): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim($data['name']);
        if ($name === '') {
            abort(422, 'Give the team a name.');
        }

        return $name;
    }

    private function teamTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->taken('teams', 'name', $name, $exceptId);
    }

    private function jobTypeTaken(string $department, string $name, ?int $exceptId = null): bool
    {
        $query = $this->connection()->table('job_types')
            ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower($department)])
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    private function departmentTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->taken('departments', 'name', $name, $exceptId);
    }

    // An empty description is stored as NULL, so "has a description" is one check everywhere.
    private static function cleanDescription(mixed $value): ?string
    {
        $description = trim((string) $value);

        return $description === '' ? null : $description;
    }

    private static function departmentKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function taken(string $table, string $column, string $value, ?int $exceptId): bool
    {
        $query = $this->connection()->table($table)->whereRaw('LOWER(TRIM('.$column.')) = ?', [mb_strtolower($value)]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    // The report builder and the boards read these lists through caches; bump both so a change shows.
    private function bump(): void
    {
        (new PortalReportProjects)->bumpCache();
        PortalCache::bump();
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }

    /**
     * A References row written with its ledger entry, so the Airtable sync creates or patches the
     * record there (2026-10-14, user). An add returns the new id; an edit ledgers only what changed.
     *
     * @param  Closure(): int  $write
     * @param  array<string, mixed>  $columns
     */
    private function ledgered(Employee $actor, string $resource, string $table, ?object $before, Closure $write, array $columns): int
    {
        return $this->ledger->within($this->connection(), function () use ($actor, $resource, $table, $before, $write, $columns): int {
            $result = $write();
            $id = $before === null ? $result : (int) $before->id;
            $changed = array_filter($columns, static fn (mixed $value, string $column): bool => $before === null || (string) ($before->{$column} ?? '') !== (string) ($value ?? ''), ARRAY_FILTER_USE_BOTH);
            $parameters = ['id' => (string) $id, 'fields' => $changed];
            $before === null
                ? $this->ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $table.':'.$id, CoreLedger::PRODUCT_PORTAL.'.'.$table, $parameters, $resource, (string) $id, (int) $actor->getKey(), is_string($actor->id_no) ? $actor->id_no : null)
                : $this->ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, CoreLedger::PRODUCT_PORTAL.'.'.$table, $parameters, $resource, (string) $id, null, (int) $actor->getKey(), is_string($actor->id_no) ? $actor->id_no : null);

            return $result;
        });
    }

    /**
     * A scope-tree change and its ledger entry as one write, on the tier the code belongs to.
     *
     * @param  array<string, string>  $fields
     */
    private function treeLedgered(Employee $actor, string $code, Closure $write, string $kind, array $fields): void
    {
        [$table, $tier] = PortalScopeTree::tableFor($code);
        $resource = 'references.scopes.t'.$tier;
        $target = CoreLedger::PRODUCT_PORTAL.'.'.$table;
        $this->ledger->within($this->connection(), function () use ($actor, $code, $write, $kind, $fields, $table, $resource, $target): void {
            $before = $this->connection()->table($table)->whereRaw('TRIM(wbs_code) = ?', [$code])->get(['id', 'airtable_record_id'])->all();
            $write();
            $actorId = (int) $actor->getKey();
            $actorIdNo = is_string($actor->id_no) ? $actor->id_no : null;
            if ($kind === 'add') {
                foreach ($this->connection()->table($table)->whereRaw('TRIM(wbs_code) = ?', [$code])->pluck('id') as $id) {
                    $this->ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $table.':'.$id, $target, ['id' => (string) $id, 'fields' => $fields], $resource, (string) $id, $actorId, $actorIdNo);
                }

                return;
            }
            foreach ($before as $row) {
                $kind === 'edit'
                    ? $this->ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, $target, ['id' => (string) $row->id, 'fields' => $fields], $resource, (string) $row->id, null, $actorId, $actorIdNo)
                    : $this->ledger->recordDelete(CoreLedger::PRODUCT_PORTAL, $target, ['id' => (string) $row->id, 'airtableRecordId' => is_string($row->airtable_record_id) && $row->airtable_record_id !== '' ? $row->airtable_record_id : null], $resource, (string) $row->id, $actorId, $actorIdNo);
            }
        });
    }

    // A References row removed for good, ledgered with the Airtable id the sync deletes there.
    private function ledgeredDelete(Employee $actor, string $resource, string $table, object $row): void
    {
        $this->ledger->within($this->connection(), function () use ($actor, $resource, $table, $row): void {
            $this->connection()->table($table)->where('id', $row->id)->delete();
            $this->ledger->recordDelete(
                CoreLedger::PRODUCT_PORTAL,
                CoreLedger::PRODUCT_PORTAL.'.'.$table,
                ['id' => (string) $row->id, 'airtableRecordId' => is_string($row->airtable_record_id ?? null) && $row->airtable_record_id !== '' ? $row->airtable_record_id : null],
                $resource,
                (string) $row->id,
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
        });
    }
}
