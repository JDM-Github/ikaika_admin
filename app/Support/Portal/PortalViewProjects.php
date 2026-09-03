<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * View Projects: the board behind the Projects screen.
 *
 * Admin and Executive get every project, each tagged with whether they are assigned.
 * Everybody else gets only their own board — the same membership cut as report pickers.
 */
final class PortalViewProjects
{
    public const CACHE_TTL_SECONDS = 30;

    public const CACHE_VERSION_KEY = 'portal:projects:version';

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>
     * }
     */
    public function list(Employee $actor): array
    {
        $employeeId = (int) $actor->getKey();
        $seesAll = PortalRole::isAdmin($actor->role ?? null, $actor->role_level ?? null);
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:projects:'.$version.':'.$employeeId.':'.($seesAll ? 'all' : 'own');

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($employeeId, $seesAll): array {
            return $this->build($employeeId, $seesAll);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>
     * }
     */
    private function build(int $employeeId, bool $seesAll): array
    {
        $rows = $this->projectRows($employeeId, $seesAll);
        $projectIds = array_map(
            static fn (object $row): int => (int) $row->id,
            $rows,
        );
        $memberNames = $this->memberNamesByProject($projectIds);
        $clientNames = $this->clientNamesByProject($projectIds);
        $scopeSummaries = $this->scopeSummariesByProject($projectIds);
        $trails = $this->trailsByProject($projectIds);
        $leadsByEmail = $this->leadsByEmail($rows);

        $projects = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $scopeSummary = $scopeSummaries[$id] ?? null;
            $scopeCodes = $scopeSummary['scopeCodes'] ?? [];
            if ($scopeSummary !== null) {
                unset($scopeSummary['scopeCodes']);
            }
            $projects[] = PortalViewProjectPresenter::item(
                $row,
                $memberNames[$id] ?? [],
                $leadsByEmail,
                $clientNames[$id] ?? [],
                $scopeSummary,
                $trails[$id] ?? [],
                $scopeCodes,
            );
        }

        usort($projects, static function (array $first, array $second): int {
            $byAssigned = ((int) ($second['isAssigned'] ?? false)) <=> ((int) ($first['isAssigned'] ?? false));
            if ($byAssigned !== 0) {
                return $byAssigned;
            }

            return strcmp((string) $first['name'], (string) $second['name']);
        });

        return [
            'section' => 'projects',
            'resource' => 'board',
            'data' => $projects,
        ];
    }

    /**
     * One read with a LEFT JOIN so isAssigned costs nothing extra per row.
     *
     * @return list<object>
     */
    private function projectRows(int $employeeId, bool $seesAll): array
    {
        $query = $this->connection()
            ->table('projects')
            ->leftJoin('employees_projects as membership', function ($join) use ($employeeId): void {
                $join->on('membership.project_id', '=', 'projects.id')
                    ->where('membership.employee_id', '=', $employeeId);
            })
            ->select([
                'projects.id',
                'projects.project_number',
                'projects.project_name',
                'projects.department',
                'projects.status',
                'projects.progress_pct',
                'projects.type_of_job',
                'projects.area_sqft',
                'projects.project_lead_email',
                'projects.forma_link',
                'projects.msteams_project_link',
                'projects.ms_planner_link',
                'projects.panoramic_link',
                'projects.ms_loop_link',
                'projects.google_drive_folder_path',
                $this->connection()->raw('CASE WHEN membership.employee_id IS NULL THEN 0 ELSE 1 END as is_assigned'),
            ])
            ->distinct();

        if (! $seesAll) {
            $query->whereNotNull('membership.employee_id');
        }

        return $query->get()->all();
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, list<string>>
     */
    private function memberNamesByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('employees_projects')
            ->join('employees', 'employees.id', '=', 'employees_projects.employee_id')
            ->whereIn('employees_projects.project_id', $projectIds)
            ->select([
                'employees_projects.project_id',
                'employees.first_name',
                'employees.last_name',
            ])
            ->orderBy('employees.first_name')
            ->orderBy('employees.last_name')
            ->get();

        $names = [];
        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $name = PortalSubmittedReportPresenter::memberName(
                is_string($row->first_name) ? $row->first_name : null,
                is_string($row->last_name) ? $row->last_name : null,
            );
            if ($name === 'Member') {
                continue;
            }
            $names[$projectId] ??= [];
            if (! in_array($name, $names[$projectId], true)) {
                $names[$projectId][] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, list<string>>
     */
    private function clientNamesByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('projects_clients')
            ->join('clients', 'clients.id', '=', 'projects_clients.client_id')
            ->whereIn('projects_clients.project_id', $projectIds)
            ->select(['projects_clients.project_id', 'clients.name'])
            ->orderBy('clients.name')
            ->get();

        $names = [];
        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $name = trim((string) ($row->name ?? ''));
            if ($name === '') {
                continue;
            }
            $names[$projectId] ??= [];
            if (! in_array($name, $names[$projectId], true)) {
                $names[$projectId][] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{
     *     tierLabel: string,
     *     total: int,
     *     completed: int,
     *     inProgress: int,
     *     overallCompletion: float
     *     scopeCodes: list<string>
     * }>
     */
    private function scopeSummariesByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        // A scope may be attached directly to a project, or indirectly through one of its
        // T3 activities. The old query only read the direct pivot, which made valid projects
        // look like they had 0 / 0 / 0 scope health.
        $directRows = $this->connection()
            ->table('projects_project_scopes')
            ->join('project_scopes', 'project_scopes.id', '=', 'projects_project_scopes.project_scope_id')
            ->whereIn('projects_project_scopes.project_id', $projectIds)
            ->select([
                'projects_project_scopes.project_id',
                'projects_project_scopes.project_scope_id',
                'project_scopes.progress_pct',
                'project_scopes.id_no',
            ])
            ->get();

        $activityRows = $this->connection()
            ->table('projects_activity_scope as project_activity')
            ->join(
                'project_scopes_t3_activities as scope_activity',
                'scope_activity.t3_activity_id',
                '=',
                'project_activity.activity_id',
            )
            ->join('project_scopes as scope', 'scope.id', '=', 'scope_activity.project_scope_id')
            ->whereIn('project_activity.project_id', $projectIds)
            ->select([
                'project_activity.project_id',
                'scope_activity.project_scope_id',
                'scope.progress_pct',
                'scope.id_no',
            ])
            ->distinct()
            ->get();

        // Some older projects were imported before the project-scope pivots existed. Their
        // effective scopes live on submitted-report activity codes instead. Only use this
        // source when a project has no explicit scope relationship, so it cannot inflate
        // newer projects that use the canonical pivots above.
        $reportActivityRows = $this->connection()
            ->table('projects_user_reports as project_report')
            ->join(
                'user_reports_activity_codes as report_activity',
                'report_activity.user_report_id',
                '=',
                'project_report.user_report_id',
            )
            ->join('activity_codes as activity_code', 'activity_code.id', '=', 'report_activity.activity_code_id')
            ->whereIn('project_report.project_id', $projectIds)
            ->select([
                'project_report.project_id',
                'activity_code.id as activity_code_id',
                'activity_code.id_no',
            ])
            ->distinct()
            ->get();

        // De-duplicate a scope reached by both pivots or by multiple activities.
        $scopes = [];
        foreach ($directRows->concat($activityRows) as $row) {
            $key = (int) $row->project_id.':'.(int) $row->project_scope_id;
            $scopes[$key] = [
                'projectId' => (int) $row->project_id,
                'progress' => $row->progress_pct,
                'code' => $row->id_no,
            ];
        }

        $projectsWithExplicitScopes = [];
        foreach ($scopes as $scope) {
            $projectsWithExplicitScopes[$scope['projectId']] = true;
        }
        foreach ($reportActivityRows as $row) {
            $projectId = (int) $row->project_id;
            if (isset($projectsWithExplicitScopes[$projectId])) {
                continue;
            }
            $key = $projectId.':activity:'.(int) $row->activity_code_id;
            $scopes[$key] = [
                'projectId' => $projectId,
                'progress' => 0.0,
                'code' => $row->id_no,
            ];
        }

        $summaries = [];
        foreach ($scopes as $scope) {
            $projectId = $scope['projectId'];
            $progress = $scope['progress'];
            $ratio = $progress === null || $progress === '' ? 0.0 : (float) $progress;
            if ($ratio > 1) {
                $ratio /= 100;
            }
            $ratio = max(0.0, min(1.0, $ratio));
            $summaries[$projectId] ??= [
                'tierLabel' => '',
                'total' => 0,
                'completed' => 0,
                'inProgress' => 0,
                'overallCompletion' => 0.0,
                'scopeCodes' => [],
            ];
            $summaries[$projectId]['total']++;
            if ($ratio >= 1) {
                $summaries[$projectId]['completed']++;
            } elseif ($ratio > 0) {
                $summaries[$projectId]['inProgress']++;
            }
            $summaries[$projectId]['overallCompletion'] += $ratio;
            $code = trim((string) ($scope['code'] ?? ''));
            if ($code !== '' && ! in_array($code, $summaries[$projectId]['scopeCodes'], true)) {
                $summaries[$projectId]['scopeCodes'][] = $code;
            }
        }

        foreach ($summaries as $projectId => $summary) {
            $summaries[$projectId]['overallCompletion'] = round(
                $summary['overallCompletion'] / max($summary['total'], 1),
                4,
            );
        }

        return $summaries;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, list<array{
     *     id: string,
     *     changedOn: string,
     *     actorName: string,
     *     summary: string
     * }>>
     */
    private function trailsByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('projects_action_history')
            ->join(
                'project_action_history',
                'project_action_history.id',
                '=',
                'projects_action_history.project_action_id',
            )
            ->whereIn('projects_action_history.project_id', $projectIds)
            ->select([
                'projects_action_history.project_id',
                'project_action_history.id',
                'project_action_history.action_name',
                'project_action_history.created_by',
                'project_action_history.remarks',
                'project_action_history.created_at',
            ])
            ->orderByDesc('project_action_history.created_at')
            ->orderByDesc('project_action_history.id')
            ->get();

        $trails = [];
        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $action = trim((string) ($row->action_name ?? ''));
            $remarks = trim((string) ($row->remarks ?? ''));
            $summary = $action !== '' ? $action : 'Project updated';
            if ($remarks !== '') {
                $summary .= ': '.$remarks;
            }

            $trails[$projectId] ??= [];
            $trails[$projectId][] = [
                'id' => (string) (int) $row->id,
                'changedOn' => PortalViewProjectPresenter::instant($row->created_at),
                'actorName' => trim((string) ($row->created_by ?? '')) ?: 'System',
                'summary' => $summary,
            ];
        }

        return $trails;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, string>
     */
    private function leadsByEmail(array $rows): array
    {
        $emails = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row->project_lead_email ?? '')));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
        if ($emails === []) {
            return [];
        }

        $found = $this->connection()
            ->table('employees')
            ->whereIn('email', array_keys($emails))
            ->select(['email', 'first_name', 'last_name'])
            ->get();

        $leads = [];
        foreach ($found as $employee) {
            $email = strtolower(trim((string) ($employee->email ?? '')));
            if ($email === '') {
                continue;
            }
            $leads[$email] = PortalSubmittedReportPresenter::memberName(
                is_string($employee->first_name) ? $employee->first_name : null,
                is_string($employee->last_name) ? $employee->last_name : null,
            );
        }

        return $leads;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
