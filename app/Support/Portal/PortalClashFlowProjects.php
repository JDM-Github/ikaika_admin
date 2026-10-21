<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * The projects ClashFlow (the desktop clash app) opens for a signed-in employee, with their role on each:
 * lead when the project's lead email is theirs, coordinator when they are a member or support member.
 * No portal rule gates it: anyone may see the projects they are on, and nothing else.
 */
final class PortalClashFlowProjects
{
    public const ROLE_LEAD = 'lead';

    public const ROLE_COORDINATOR = 'coordinator';

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployee(Employee $employee): array
    {
        $email = strtolower(trim((string) $employee->email));
        $id = (int) $employee->getKey();

        $projects = $this->connection()->table('projects')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($email, $id): void {
                $query->whereIn('id', fn ($members) => $members->select('project_id')->from('employees_projects')->where('employee_id', $id));
                if ($email !== '') {
                    $query->orWhereRaw('LOWER(TRIM(COALESCE(project_lead_email, \'\'))) = ?', [$email]);
                }
            })
            ->orderByDesc('project_number')
            ->orderBy('id')
            ->get(['id', 'project_number', 'project_name', 'department', 'status', 'project_lead_email']);

        if ($projects->isEmpty()) {
            return [];
        }

        $projectIds = $projects->pluck('id')->map(static fn (mixed $value): int => (int) $value)->all();
        $leadEmails = $projects->pluck('project_lead_email')
            ->map(static fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $members = $this->connection()->table('employees_projects')
            ->join('employees', 'employees.id', '=', 'employees_projects.employee_id')
            ->whereIn('employees_projects.project_id', $projectIds)
            ->get(['employees_projects.project_id', 'employees.id', 'employees.first_name', 'employees.last_name', 'employees.email', 'employees.status']);

        $leads = $leadEmails === [] ? collect() : $this->connection()->table('employees')
            ->whereIn($this->connection()->raw('LOWER(TRIM(email))'), $leadEmails)
            ->get(['id', 'first_name', 'last_name', 'email', 'status'])
            ->keyBy(static fn (object $row): string => strtolower(trim((string) $row->email)));

        $rows = [];
        foreach ($projects as $project) {
            $leadEmail = strtolower(trim((string) $project->project_lead_email));
            $lead = $leads->get($leadEmail);
            $team = [];
            if ($lead !== null) {
                $team[(int) $lead->id] = $this->person($lead, self::ROLE_LEAD);
            }
            foreach ($members->where('project_id', $project->id) as $member) {
                // A lead who is also on the member list stays the lead.
                $team[(int) $member->id] ??= $this->person($member, self::ROLE_COORDINATOR);
            }

            $rows[] = [
                'id' => (int) $project->id,
                'number' => (string) ($project->project_number ?? ''),
                'name' => (string) ($project->project_name ?? ''),
                'department' => (string) ($project->department ?? ''),
                'status' => (string) ($project->status ?? ''),
                'role' => $email !== '' && $leadEmail === $email ? self::ROLE_LEAD : self::ROLE_COORDINATOR,
                'leadEmail' => $leadEmail,
                'team' => array_values($team),
            ];
        }

        return $rows;
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

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
