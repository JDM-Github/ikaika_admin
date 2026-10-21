<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ClashFlow (the desktop clash app) projects (2026-10-21, user). Nothing is created for every portal
 * project: someone creates a ClashFlow project, either
 * - team: linked to one portal project, so roles follow it: lead = the project's lead email,
 *   coordinator = a member or support member, viewer = an Admin not on it (any department) or a
 *   ProjectAdmin not on it (their own department only); anyone else does not see it. Admins may link
 *   any portal project, ProjectAdmins only the ones they lead in their department. A Done or Closed
 *   portal project is listed but cannot be linked (2026-10-21, user).
 * - solo: not linked; bound to its creator's account, and only they see it (as its lead).
 */
final class PortalClashFlowProjects
{
    public const ROLE_LEAD = 'lead';

    public const ROLE_COORDINATOR = 'coordinator';

    public const ROLE_VIEWER = 'viewer';

    public const MODE_TEAM = 'team';

    public const MODE_SOLO = 'solo';

    // Portal statuses that end a project: still listed, never linked to a new ClashFlow project.
    private const FINISHED = ['done', 'closed'];

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployee(Employee $employee): array
    {
        $rows = $this->visible($employee);
        if ($rows === []) {
            return [];
        }

        $portalIds = array_values(array_filter(array_map(static fn (array $row): ?int => $row['project']->portal_project_id === null ? null : (int) $row['project']->portal_project_id, $rows)));
        $teams = $this->teams($portalIds);

        return array_map(fn (array $row): array => $this->present($row['project'], $row['role'], $teams, $employee), $rows);
    }

    // The person's role on a ClashFlow project, or null when they may not see it.
    public function roleOn(Employee $employee, int $clashflowId): ?string
    {
        foreach ($this->visible($employee) as $row) {
            if ((int) $row['project']->id === $clashflowId) {
                return $row['role'];
            }
        }

        return null;
    }

    /**
     * ClashFlow projects the person may write (lead or coordinator).
     *
     * @return list<int>
     */
    public function writableIds(Employee $employee): array
    {
        $ids = [];
        foreach ($this->visible($employee) as $row) {
            if ($row['role'] !== self::ROLE_VIEWER) {
                $ids[] = (int) $row['project']->id;
            }
        }

        return $ids;
    }

    public function canCreateTeam(Employee $employee): bool
    {
        return in_array($this->portalRole($employee), ['admin', 'projectadmin'], true);
    }

    public function isAdmin(Employee $employee): bool
    {
        return $this->portalRole($employee) === 'admin';
    }

    /**
     * The departments an Admin can filter by (those that have projects).
     *
     * @return list<string>
     */
    public function departments(): array
    {
        return $this->connection()->table('projects')->whereNull('deleted_at')->whereNotNull('department')
            ->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')
            ->map(static fn (mixed $value): string => (string) $value)->all();
    }

    /**
     * Portal projects the person may turn into a team ClashFlow project, not linked yet. An Admin picks the
     * department ('' = all); a ProjectAdmin sees the ones they lead in their own department. Done and
     * Closed projects come last with canLink false.
     *
     * @return list<array{id: int, number: string, name: string, department: string, status: string, canLink: bool}>
     */
    public function linkable(Employee $employee, string $search, string $department = ''): array
    {
        if (! $this->canCreateTeam($employee)) {
            return [];
        }

        $query = $this->connection()->table('projects')
            ->whereNull('deleted_at')
            ->whereNotIn('id', fn ($linked) => $linked->select('portal_project_id')->from('clashflow_projects')->whereNotNull('portal_project_id'));
        if ($this->isAdmin($employee)) {
            if (trim($department) !== '') {
                $query->where('department', trim($department));
            }
        } else {
            $query->whereRaw('LOWER(TRIM(COALESCE(project_lead_email, \'\'))) = ?', [$this->email($employee)])
                ->where('department', (string) $employee->department);
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($match) => $match->where('project_number', 'like', $like)->orWhere('project_name', 'like', $like));
        }

        return $query
            ->orderByRaw("CASE WHEN LOWER(COALESCE(status, '')) IN ('done', 'closed') THEN 1 ELSE 0 END")
            ->orderByDesc('project_number')->limit(100)
            ->get(['id', 'project_number', 'project_name', 'department', 'status'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => (string) ($row->project_number ?? ''),
                'name' => trim((string) ($row->project_name ?? '')),
                'department' => (string) ($row->department ?? ''),
                'status' => (string) ($row->status ?? ''),
                'canLink' => ! $this->isFinished($row->status),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{mode: string, portalProjectId?: int|null, name?: string|null, number?: string|null}  $input
     * @return array<string, mixed>
     */
    public function create(Employee $employee, array $input): array
    {
        $row = ['mode' => $input['mode'], 'owner_id' => (int) $employee->getKey(), 'created_at' => now()];
        if ($input['mode'] === self::MODE_TEAM) {
            $portalId = (int) ($input['portalProjectId'] ?? 0);
            if (! $this->isLinkable($employee, $portalId)) {
                abort(403, 'You cannot start ClashFlow on that project.');
            }
            $row['portal_project_id'] = $portalId;
        } else {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                abort(422, 'Give the project a name.');
            }
            $row['name'] = mb_substr($name, 0, 255);
            $row['number'] = mb_substr(trim((string) ($input['number'] ?? '')), 0, 100);
        }

        $id = $this->connection()->table('clashflow_projects')->insertGetId($row);
        foreach ($this->forEmployee($employee) as $project) {
            if ($project['id'] === $id) {
                return $project;
            }
        }

        // A creator who is not on the linked project and holds no viewer role cannot see it; say so plainly.
        abort(409, 'The project was created but you are not on it.');
    }

    // The same rule as linkable(), for one project: live, not linked yet, and an Admin's or its lead's.
    private function isLinkable(Employee $employee, int $portalId): bool
    {
        if ($portalId <= 0 || ! $this->canCreateTeam($employee)) {
            return false;
        }
        $project = $this->connection()->table('projects')->whereNull('deleted_at')->where('id', $portalId)
            ->first(['project_lead_email', 'department', 'status']);
        if ($project === null || $this->isFinished($project->status)
            || $this->connection()->table('clashflow_projects')->where('portal_project_id', $portalId)->exists()) {
            return false;
        }

        return $this->isAdmin($employee)
            || (strtolower(trim((string) $project->project_lead_email)) === $this->email($employee)
                && (string) $project->department === (string) $employee->department);
    }

    /**
     * Every ClashFlow project the person may see, with their role on it.
     *
     * @return list<array{project: object, role: string}>
     */
    private function visible(Employee $employee): array
    {
        $me = (int) $employee->getKey();
        $email = $this->email($employee);
        $isAdmin = $this->isAdmin($employee);
        // A ProjectAdmin views only their own department; an Admin views every department.
        $viewsDepartment = $this->portalRole($employee) === 'projectadmin' ? (string) $employee->department : null;
        $memberOf = $this->connection()->table('employees_projects')->where('employee_id', $me)->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)->flip();

        $projects = $this->connection()->table('clashflow_projects as cf')
            ->leftJoin('projects as p', function ($join): void {
                $join->on('p.id', '=', 'cf.portal_project_id')->whereNull('p.deleted_at');
            })
            ->where(fn ($query) => $query->where('cf.mode', self::MODE_TEAM)->orWhere('cf.owner_id', $me))
            ->orderBy('cf.id')
            ->get(['cf.id', 'cf.mode', 'cf.portal_project_id', 'cf.name', 'cf.number', 'cf.owner_id',
                'p.id as portal_id', 'p.project_number', 'p.project_name', 'p.department', 'p.status', 'p.project_lead_email']);

        $rows = [];
        foreach ($projects as $project) {
            if ($project->mode === self::MODE_SOLO) {
                $rows[] = ['project' => $project, 'role' => self::ROLE_LEAD];

                continue;
            }
            if ($project->portal_id === null) {
                continue;
            }
            $role = match (true) {
                $email !== '' && strtolower(trim((string) $project->project_lead_email)) === $email => self::ROLE_LEAD,
                $memberOf->has((int) $project->portal_id) => self::ROLE_COORDINATOR,
                $isAdmin => self::ROLE_VIEWER,
                $viewsDepartment !== null && (string) $project->department === $viewsDepartment => self::ROLE_VIEWER,
                default => null,
            };
            if ($role !== null) {
                $rows[] = ['project' => $project, 'role' => $role];
            }
        }

        return $rows;
    }

    /**
     * Each portal project's people: the lead (by email) plus members and support members.
     *
     * @param  list<int>  $portalIds
     * @return array<int, list<array{id: int, name: string, email: string, role: string, active: bool}>>
     */
    private function teams(array $portalIds): array
    {
        if ($portalIds === []) {
            return [];
        }
        $projects = $this->connection()->table('projects')->whereIn('id', $portalIds)->get(['id', 'project_lead_email']);
        $leadEmails = $projects->pluck('project_lead_email')
            ->map(static fn (mixed $value): string => strtolower(trim((string) $value)))->filter()->unique()->values()->all();
        /** @var Collection<string, object> $leads */
        $leads = $leadEmails === [] ? collect() : $this->connection()->table('employees')
            ->whereIn($this->connection()->raw('LOWER(TRIM(email))'), $leadEmails)
            ->get(['id', 'first_name', 'last_name', 'email', 'status'])
            ->keyBy(static fn (object $row): string => strtolower(trim((string) $row->email)));
        $members = $this->connection()->table('employees_projects')
            ->join('employees', 'employees.id', '=', 'employees_projects.employee_id')
            ->whereIn('employees_projects.project_id', $portalIds)
            ->get(['employees_projects.project_id', 'employees.id', 'employees.first_name', 'employees.last_name', 'employees.email', 'employees.status']);

        $teams = [];
        foreach ($projects as $project) {
            $team = [];
            $lead = $leads->get(strtolower(trim((string) $project->project_lead_email)));
            if ($lead !== null) {
                $team[(int) $lead->id] = $this->person($lead, self::ROLE_LEAD);
            }
            foreach ($members->where('project_id', $project->id) as $member) {
                // A lead who is also on the member list stays the lead.
                $team[(int) $member->id] ??= $this->person($member, self::ROLE_COORDINATOR);
            }
            $teams[(int) $project->id] = array_values($team);
        }

        return $teams;
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $teams
     * @return array<string, mixed>
     */
    private function present(object $project, string $role, array $teams, Employee $employee): array
    {
        $solo = $project->mode === self::MODE_SOLO;

        return [
            'id' => (int) $project->id,
            'mode' => $project->mode,
            'portalProjectId' => $solo ? null : (int) $project->portal_id,
            'number' => (string) ($solo ? $project->number : $project->project_number) ?: '',
            'name' => trim((string) ($solo ? $project->name : $project->project_name)),
            'department' => $solo ? '' : (string) ($project->department ?? ''),
            'status' => $solo ? '' : (string) ($project->status ?? ''),
            'role' => $role,
            'team' => $solo
                ? [$this->person((object) ['id' => $employee->getKey(), 'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'email' => $employee->email, 'status' => $employee->status], self::ROLE_LEAD)]
                : ($teams[(int) $project->portal_id] ?? []),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, active: bool}
     */
    private function person(object $row, string $role): array
    {
        return [
            'id' => (int) $row->id,
            'name' => trim(trim((string) $row->first_name).' '.trim((string) $row->last_name)),
            'email' => strtolower(trim((string) $row->email)),
            'role' => $role,
            'active' => strtolower(trim((string) $row->status)) === 'active',
        ];
    }

    private function isFinished(mixed $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::FINISHED, true);
    }

    private function portalRole(Employee $employee): string
    {
        return strtolower(trim((string) $employee->role));
    }

    private function email(Employee $employee): string
    {
        return strtolower(trim((string) $employee->email));
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
