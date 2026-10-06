<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
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
final class PortalProjectSettings
{
    public function __construct(private readonly PortalAudit $audit) {}

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

        $departments = [];
        foreach ($this->connection()->table('departments')->orderBy('name')->get(['id', 'name']) as $row) {
            $departments[] = ['id' => (string) (int) $row->id, 'name' => trim((string) $row->name)];
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
            'resource' => 'project-settings',
            'data' => [
                'scopes' => $scopes,
                'jobTypes' => $jobTypes,
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

        $id = (int) $this->connection()->table('clients')->insertGetId(['name' => $name, 'client_id' => $code]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.settings.client', '{UserName|You} added the client '.$name.' ('.$code.')', (string) $id, $request);

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

        $this->connection()->table('clients')->where('id', $id)->update(['name' => $name, 'client_id' => $code]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.settings.client', '{UserName|You} changed the client '.$name.' ('.$code.')', (string) $id, $request);

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

        $this->connection()->table('clients')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.settings.client', '{UserName|You} removed the client '.trim((string) $row->name), (string) $id, $request);

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

        $id = (int) $this->connection()->table('activity_codes')->insertGetId([
            'id_no' => $code,
            'name' => trim($data['name']),
            'department' => trim($data['department']),
        ]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.settings.scope', '{UserName|You} added the scope '.$code, (string) $id, $request);

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

        $this->connection()->table('activity_codes')->where('id', $id)->update([
            'id_no' => $code,
            'name' => trim($data['name']),
            'department' => trim($data['department']),
        ]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.settings.scope', '{UserName|You} changed the scope '.$code, (string) $id, $request);

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

        $this->connection()->table('activity_codes')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.settings.scope', '{UserName|You} removed the scope '.trim((string) $row->id_no), (string) $id, $request);

        return $this->index();
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
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.settings.type', '{UserName|You} added the project type '.$name, (string) $id, $request);

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
        $this->connection()->table('job_types')->where('id', $id)->update(['department' => $department, 'name' => $name]);
        // The report builder keys a type's allowed activities by name, so a rename carries them over.
        if ($oldName !== $name) {
            $this->connection()->table('job_type_allowed_activities')->where('job_type', $oldName)->update(['job_type' => $name]);
        }
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.settings.type', '{UserName|You} changed the project type '.$name, (string) $id, $request);

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
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.settings.type', '{UserName|You} removed the project type '.trim((string) $row->name), (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addDepartment(Employee $actor, Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim($data['name']);
        if ($name === '') {
            abort(422, 'Give the department a name.');
        }
        if ($this->departmentTaken($name)) {
            abort(422, 'That department is already on the list.');
        }

        $id = (int) $this->connection()->table('departments')->insertGetId(['name' => $name]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.settings.department', '{UserName|You} added the department '.$name, (string) $id, $request);

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
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.settings.department', '{UserName|You} removed the department '.trim((string) $row->name), (string) $id, $request);

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
}
