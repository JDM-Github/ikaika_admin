<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The project board behind View Projects and Track Projects.
 *
 * View Projects and Track Projects each follow their rule's reach: own = the projects they are on, department = those
 * plus their department's, all = every project. Membership is never lost at any reach.
 * Each project also says whether this person may edit it, manage its scopes or delete it, and
 * carries its work from reports (PortalProjectWork).
 */
final class PortalViewProjects
{
    public const CACHE_TTL_SECONDS = 30;

    public const CACHE_VERSION_KEY = PortalCache::DATA_VERSION_KEY;

    // Rows Airtable wrote about reports, not changes: the Activity tab and the dashboard show those dates.
    private const REPORT_TRAIL_PREFIXES = ['initial report was on', 'last report was on'];

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>
     * }
     */
    // View Projects follows its rule's reach (2026-10-01): members own, admins and executives all by preset.
    public function list(Employee $actor, Request $request): array
    {
        return $this->board($actor, PortalRules::active($actor, $request)['projects.view'] ?? 'own', PortalProjectPowers::reaches($actor, $request));
    }

    /**
     * Track Projects, cut to how far the person's Track Projects rule reaches.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>}
     */
    public function track(Employee $actor, Request $request): array
    {
        return $this->board($actor, PortalRules::active($actor, $request)['projects.track'] ?? 'own', PortalProjectPowers::reaches($actor, $request));
    }

    /**
     * Cached per person and per reach, Edit, Manage scopes and Delete included, so each project says
     * what this person may do with it.
     *
     * @param  array<string, ?string>  $powers
     * @return array{section: string, resource: string, data: list<array<string, mixed>>}
     */
    private function board(Employee $actor, string $reach, array $powers): array
    {
        $employeeId = (int) $actor->getKey();
        $department = $reach === 'department' ? PortalPreview::department($actor->department) : '';
        $leadEmail = strtolower(trim((string) ($actor->email ?? '')));
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $actorDepartment = PortalPreview::department($actor->department);
        $key = 'portal:projects:'.$version.':'.$employeeId.':'.$reach.':'.md5($department.'|'.$leadEmail.'|'.$actorDepartment.'|'.json_encode($powers));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $employeeId, $reach, $department, $leadEmail, $powers): array {
            return $this->build($actor, $employeeId, $reach, $department, $leadEmail, $powers);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * One project's effective scope codes: the WBS codes saved from Manage Scopes, else the codes its
     * pivots or reports name. The editor shows the same list the board does.
     *
     * @return list<string>
     */
    public function scopeCodesForProject(int $projectId): array
    {
        $summaries = $this->scopeSummariesByProject([$projectId]);

        return $summaries[$projectId]['scopeCodes'] ?? [];
    }

    /**
     * @param  array<string, ?string>  $powers
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>
     * }
     */
    private function build(Employee $actor, int $employeeId, string $reach, string $department, string $leadEmail, array $powers): array
    {
        $rows = $this->projectRows($employeeId, $reach, $department, $leadEmail);
        $projectIds = array_map(
            static fn (object $row): int => (int) $row->id,
            $rows,
        );
        $members = $this->membersByProject($projectIds);
        $memberNames = $this->namesByProject($members);
        $memberPhotos = $this->photosByProject($members);
        $clientNames = $this->clientNamesByProject($projectIds);
        $scopeSummaries = $this->scopeSummariesByProject($projectIds);
        $trails = $this->trailsByProject($projectIds);
        $trailPhotos = $this->actorPhotos($trails);
        $leadsByEmail = $this->leadsByEmail($rows);
        $work = (new PortalProjectWork)->byProject($rows, CarbonImmutable::today());
        $activityCodes = PortalActivityScopes::all();

        $projects = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $scopeSummary = $scopeSummaries[$id] ?? null;
            $scopeCodes = $scopeSummary['scopeCodes'] ?? [];
            if ($scopeSummary !== null) {
                unset($scopeSummary['scopeCodes']);
            }
            // Each trail row carries its actor's photo, so the Contributors list shows a face.
            $trail = array_map(
                static fn (array $entry): array => [...$entry, 'actorPhotoUrl' => $trailPhotos[$entry['actorName']] ?? null],
                $trails[$id] ?? [],
            );
            $projects[] = [
                ...PortalViewProjectPresenter::item(
                    $row,
                    $memberNames[$id] ?? [],
                    $leadsByEmail,
                    $clientNames[$id] ?? [],
                    $scopeSummary,
                    $trail,
                    $scopeCodes,
                    $memberPhotos[$id] ?? [],
                ),
                ...PortalProjectPowers::flags($powers, $actor, $row),
                'team' => PortalViewProjectPresenter::team(
                    $leadsByEmail[strtolower(trim((string) ($row->project_lead_email ?? '')))] ?? null,
                    $members[$id] ?? [],
                ),
                'work' => $work[$id],
                'scopeOptions' => PortalActivityScopes::forDepartment($activityCodes, $row->department ?? null),
            ];
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
     * One read with a LEFT JOIN so isAssigned costs nothing extra per row. A project's lead counts as on
     * it, member row or not: they may edit and delete it, so it has to be on their own board.
     *
     * @return list<object>
     */
    private function projectRows(int $employeeId, string $reach, string $department, string $leadEmail): array
    {
        $leads = $leadEmail !== '' ? 'LOWER(TRIM(COALESCE(projects.project_lead_email, \'\'))) = ?' : '1 = 0';
        $leadBindings = $leadEmail !== '' ? [$leadEmail] : [];
        $isOwn = static function ($own) use ($leads, $leadBindings): void {
            $own->whereNotNull('membership.employee_id')->orWhereRaw($leads, $leadBindings);
        };

        $query = $this->connection()
            ->table('projects')
            // A deleted project is in the Recycle Bin until somebody restores or purges it.
            ->whereNull('projects.deleted_at')
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
                'projects.type_of_job',
                'projects.area_sqft',
                'projects.project_lead_email',
                'projects.project_creation_status',
                'projects.requested_by',
                'projects.forma_link',
                'projects.msteams_project_link',
                'projects.ms_planner_link',
                'projects.panoramic_link',
                'projects.ms_loop_link',
                'projects.google_drive_folder_path',
            ])
            ->selectRaw('CASE WHEN membership.employee_id IS NOT NULL OR '.$leads.' THEN 1 ELSE 0 END as is_assigned', $leadBindings)
            ->selectRaw('CASE WHEN membership.employee_id IS NOT NULL THEN 1 ELSE 0 END as is_member')
            ->distinct();

        // Their own projects at every reach; a department with no name adds nothing.
        if ($reach === 'department' && $department !== '') {
            $query->where(function ($visible) use ($department, $isOwn): void {
                $visible->where($isOwn)
                    ->orWhereRaw('LOWER(TRIM(projects.department)) = ?', [$department]);
            });
        } elseif ($reach !== 'all') {
            $query->where($isOwn);
        }

        return $query->get()->all();
    }

    /**
     * Each project's members as {id, name}, so the board can carry their names and their photos.
     *
     * @param  list<int>  $projectIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function membersByProject(array $projectIds): array
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
                'employees_projects.role_on_project',
                'employees.id',
                'employees.first_name',
                'employees.last_name',
            ])
            ->orderBy('employees.first_name')
            ->orderBy('employees.last_name')
            // A person's member row before their support row, so someone on both reads as a member.
            ->orderBy('employees_projects.role_on_project')
            ->get();

        $members = [];
        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $name = PortalSubmittedReportPresenter::memberName(
                is_string($row->first_name) ? $row->first_name : null,
                is_string($row->last_name) ? $row->last_name : null,
            );
            if ($name === 'Member') {
                continue;
            }
            // Support only when that is all they are on it; any other row makes them a member.
            $role = strtolower(trim((string) $row->role_on_project)) === 'support' ? 'support' : 'member';
            $members[$projectId] ??= [];
            $seen = false;
            foreach ($members[$projectId] as $existing) {
                if ($existing['name'] === $name) {
                    $seen = true;
                    break;
                }
            }
            if (! $seen) {
                $members[$projectId][] = ['id' => (int) $row->id, 'name' => $name, 'role' => $role];
            }
        }

        return $members;
    }

    /**
     * @param  array<int, list<array{id: int, name: string}>>  $members
     * @return array<int, list<string>>
     */
    private function namesByProject(array $members): array
    {
        $names = [];
        foreach ($members as $projectId => $list) {
            $names[$projectId] = array_map(static fn (array $member): string => $member['name'], $list);
        }

        return $names;
    }

    /**
     * @param  array<int, list<array{id: int, name: string}>>  $members
     * @return array<int, array<string, string>>
     */
    private function photosByProject(array $members): array
    {
        $ids = [];
        foreach ($members as $list) {
            foreach ($list as $member) {
                $ids[] = $member['id'];
            }
        }
        $photos = PortalEmployeePresenter::photoUrls($ids);

        $byProject = [];
        foreach ($members as $projectId => $list) {
            $byProject[$projectId] = [];
            foreach ($list as $member) {
                if (isset($photos[$member['id']])) {
                    $byProject[$projectId][$member['name']] = $photos[$member['id']];
                }
            }
        }

        return $byProject;
    }

    /**
     * Trail actors are stored as a name, so match them to employees by full name for their photo.
     *
     * @param  array<int, list<array{actorName: string}>>  $trails
     * @return array<string, string>
     */
    private function actorPhotos(array $trails): array
    {
        $names = [];
        foreach ($trails as $entries) {
            foreach ($entries as $entry) {
                $name = trim($entry['actorName']);
                if ($name !== '' && $name !== 'System') {
                    $names[$name] = true;
                }
            }
        }
        if ($names === []) {
            return [];
        }

        $idsByName = PortalEmployeePresenter::idsByFullName();
        $wanted = [];
        foreach (array_keys($names) as $name) {
            if (isset($idsByName[$name])) {
                $wanted[$name] = $idsByName[$name];
            }
        }
        $photos = PortalEmployeePresenter::photoUrls(array_values($wanted));

        $byName = [];
        foreach ($wanted as $name => $id) {
            if (isset($photos[$id])) {
                $byName[$name] = $photos[$id];
            }
        }

        return $byName;
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
     * The scopes attached to each project, by WBS code where Manage Scopes saved them, else by the
     * activity codes its reports name. Counts and codes only: a project's real work comes from its
     * reports (PortalProjectWork), so no completion figure is invented here.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array{
     *     tierLabel: string,
     *     total: int,
     *     scopeCodes: list<string>
     * }>
     */
    private function scopeSummariesByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        // Scopes saved from Manage Scopes win: a project with rows here reads only these, by WBS code.
        $wbsRows = $this->connection()
            ->table('projects_wbs_scopes')
            ->whereIn('project_id', $projectIds)
            ->orderBy('wbs_code')
            ->get(['project_id', 'wbs_code']);
        $projectsWithWbsScopes = [];
        foreach ($wbsRows as $row) {
            $projectsWithWbsScopes[(int) $row->project_id] = true;
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
        foreach ($wbsRows as $row) {
            $code = (string) $row->wbs_code;
            $scopes[(int) $row->project_id.':wbs:'.$code] = [
                'projectId' => (int) $row->project_id,
                'code' => $code,
            ];
        }
        foreach ($directRows->concat($activityRows) as $row) {
            if (isset($projectsWithWbsScopes[(int) $row->project_id])) {
                continue;
            }
            $key = (int) $row->project_id.':'.(int) $row->project_scope_id;
            $scopes[$key] = [
                'projectId' => (int) $row->project_id,
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
                'code' => $row->id_no,
            ];
        }

        $summaries = [];
        foreach ($scopes as $scope) {
            $projectId = $scope['projectId'];
            $summaries[$projectId] ??= [
                'tierLabel' => '',
                'total' => 0,
                'scopeCodes' => [],
            ];
            $summaries[$projectId]['total']++;
            $code = trim((string) ($scope['code'] ?? ''));
            if ($code !== '' && ! in_array($code, $summaries[$projectId]['scopeCodes'], true)) {
                $summaries[$projectId]['scopeCodes'][] = $code;
            }
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
            ->where(function ($changes): void {
                foreach (self::REPORT_TRAIL_PREFIXES as $prefix) {
                    $changes->whereRaw("LOWER(TRIM(COALESCE(project_action_history.action_name, ''))) NOT LIKE ?", [$prefix.'%']);
                }
            })
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
