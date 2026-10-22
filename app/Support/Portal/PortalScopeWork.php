<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Manage Scopes (2026-10-13, user): who is assigned to each of a project's scopes (any level, several
 * each, only the project's own members and support members), and the progress (0-100%) they report on
 * those scopes through their daily and late reports. The latest entry is a scope's current progress;
 * every entry keeps the report it came with, so View Reports shows it.
 *
 * The Airtable-mirrored Project Scopes rows (2026-10-22, user) follow the assignments and the latest
 * reported progress: one record per assigned T3 activity or T4 task, created, patched and deleted here.
 */
final class PortalScopeWork
{
    private const CORE_TARGET = 'portal.project_scopes';

    private const CORE_RESOURCE = 'projects.scopes';

    public function __construct(private readonly ?CoreLedger $ledger = null) {}

    /**
     * Each scope's assignees on one project, as employee ids.
     *
     * @return array<string, list<string>>
     */
    public function assigneesFor(int $projectId): array
    {
        $byCode = [];
        foreach ($this->connection()->table('projects_scope_assignees')->where('project_id', $projectId)->orderBy('wbs_code')->orderBy('employee_id')->get() as $row) {
            $byCode[(string) $row->wbs_code][] = (string) (int) $row->employee_id;
        }

        return $byCode;
    }

    /**
     * Keeps only assignees the project holds, on scopes the project holds, and replaces the old ones.
     *
     * @param  array<mixed>  $raw  scope code => list of employee ids
     * @param  list<string>  $scopeCodes
     * @param  list<int>  $peopleIds
     */
    public function replaceAssignees(int $projectId, mixed $raw, array $scopeCodes, array $peopleIds, ?Employee $actor = null): void
    {
        if (! is_array($raw)) {
            abort(422, 'Scope assignees must be a list per scope.');
        }
        $rows = [];
        foreach ($raw as $code => $ids) {
            $code = trim((string) $code);
            if (! in_array($code, $scopeCodes, true) || ! is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $employeeId = (int) $id;
                if (! in_array($employeeId, $peopleIds, true)) {
                    abort(422, 'Assign a scope only to the project\'s members and support members.');
                }
                $rows[$code.':'.$employeeId] = ['project_id' => $projectId, 'wbs_code' => $code, 'employee_id' => $employeeId];
            }
        }
        // Only a project the portal assigns scopes on owns Airtable's rows: a project never managed
        // here keeps the rows HR made, even when an unrelated edit prunes nothing.
        $managed = $rows !== [] || $this->hasAssignments($projectId);
        $this->connection()->table('projects_scope_assignees')->where('project_id', $projectId)->delete();
        if ($rows !== []) {
            $this->connection()->table('projects_scope_assignees')->insert(array_values($rows));
        }
        $this->bump();
        if ($managed) {
            $this->reconcileProjects([$projectId], $actor);
        }
    }

    // A scope taken off the project, or a person off it, takes their assignments along.
    public function prune(int $projectId, array $scopeCodes, array $peopleIds, ?Employee $actor = null): void
    {
        $managed = $this->hasAssignments($projectId);
        $query = $this->connection()->table('projects_scope_assignees')->where('project_id', $projectId);
        $query->where(function ($stale) use ($scopeCodes, $peopleIds): void {
            $stale->whereNotIn('wbs_code', $scopeCodes === [] ? [''] : $scopeCodes)
                ->orWhereNotIn('employee_id', $peopleIds === [] ? [0] : $peopleIds);
        })->delete();
        if ($managed) {
            $this->reconcileProjects([$projectId], $actor);
        }
    }

    private function hasAssignments(int $projectId): bool
    {
        return $this->connection()->table('projects_scope_assignees')->where('project_id', $projectId)->exists();
    }

    /**
     * Each scope's assignees by name on these projects, for the board's scope tree.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array<string, list<string>>>
     */
    public function assigneeNames(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $names = [];
        $rows = $this->connection()->table('projects_scope_assignees as assigned')
            ->join('employees', 'employees.id', '=', 'assigned.employee_id')
            ->whereIn('assigned.project_id', $projectIds)
            ->orderBy('employees.first_name')
            ->get(['assigned.project_id', 'assigned.wbs_code', 'employees.first_name', 'employees.last_name']);
        foreach ($rows as $row) {
            $names[(int) $row->project_id][(string) $row->wbs_code][] = trim((string) $row->first_name.' '.(string) $row->last_name);
        }

        return $names;
    }

    /**
     * Each scope's current progress on these projects: from the report with the newest day, the later
     * filing breaking a tie. A late report for an older day is kept on that day but does not override it.
     *
     * ponytail: reads every progress row of these projects; group in SQL once a project holds thousands.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array<string, int>>
     */
    public function latestProgress(array $projectIds): array
    {
        return array_map(
            static fn (array $scopes): array => array_map(static fn (array $latest): int => $latest['percent'], $scopes),
            $this->latestApproved($projectIds),
        );
    }

    /**
     * The same newest approved figure with the report day it came from, for the report picker's hint.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array<string, array{percent: int, asOf: ?string}>>
     */
    public function latestApproved(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        // Only an approved report's progress counts (2026-10-14, user); among those the newest wins, whoever filed it.
        $rows = $this->connection()->table('projects_scope_progress as progress')
            ->join('user_reports', 'user_reports.id', '=', 'progress.user_report_id')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'progress.user_report_id')
            ->whereIn('progress.project_id', $projectIds)
            ->orderByRaw('progress.report_date IS NULL, progress.report_date DESC')
            ->orderByDesc('progress.id')
            ->get(['progress.project_id', 'progress.wbs_code', 'progress.percent', 'progress.report_date', 'user_reports.late_submission', 'user_reports.late_submission_approval', 'employees_user_reports.is_flag', 'employees_user_reports.flag_status']);
        $progress = [];
        foreach ($rows as $row) {
            $status = PortalReportReview::status(
                PortalSubmittedReportPresenter::kind($row->late_submission ?? null),
                $row->is_flag ?? false,
                $row->flag_status ?? null,
                $row->late_submission_approval ?? null,
            );
            if ($status === PortalReportReview::APPROVED) {
                $progress[(int) $row->project_id][(string) $row->wbs_code] ??= [
                    'percent' => (int) $row->percent,
                    'asOf' => $row->report_date === null ? null : substr((string) $row->report_date, 0, 10),
                ];
            }
        }

        return $progress;
    }

    /**
     * The progress a report carries, kept only for scopes the reporter is assigned on that project.
     *
     * @param  mixed  $raw  list of {projectId, code, percent}
     * @return list<array{project_id: int, wbs_code: string, percent: int}>
     */
    public function validated(int $employeeId, mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw)) {
            abort(422, 'Scope progress must be a list.');
        }
        $entries = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                abort(422, 'Each scope progress needs a project, a scope and a percent.');
            }
            $projectId = (int) ($entry['projectId'] ?? 0);
            $code = trim((string) ($entry['code'] ?? ''));
            $percent = $entry['percent'] ?? null;
            if (! is_numeric($percent) || (int) $percent < 0 || (int) $percent > 100) {
                abort(422, 'Scope progress is a percent from 0 to 100.');
            }
            $assigned = $this->connection()->table('projects_scope_assignees')
                ->where(['project_id' => $projectId, 'wbs_code' => $code, 'employee_id' => $employeeId])->exists();
            if (! $assigned) {
                abort(422, 'Report progress only on scopes you are assigned.');
            }
            $entries[$projectId.':'.$code] = ['project_id' => $projectId, 'wbs_code' => $code, 'percent' => (int) $percent];
        }

        return array_values($entries);
    }

    /**
     * An edited day's figures are replaced wholesale (2026-10-14): the rows its old lines held go with it.
     *
     * @return list<int> the projects whose progress went
     */
    public function clearDay(int $employeeId, string $reportDate): array
    {
        $query = $this->connection()->table('projects_scope_progress')
            ->where('employee_id', $employeeId)
            ->where('report_date', $reportDate);
        $projects = $query->pluck('project_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $deleted = $query->delete();
        if ($deleted > 0) {
            $this->bump();
        }

        return $projects;
    }

    /**
     * @param  list<array{project_id: int, wbs_code: string, percent: int}>  $entries
     */
    public function record(int $employeeId, ?int $reportId, ?string $reportDate, array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $now = now();
        $this->connection()->table('projects_scope_progress')->insert(array_map(
            static fn (array $entry): array => [...$entry, 'employee_id' => $employeeId, 'user_report_id' => $reportId, 'report_date' => $reportDate, 'created_at' => $now],
            $entries,
        ));
        $this->bump();
    }

    /**
     * Brings the Airtable-mirrored Project Scopes rows in line with a project's assignments and the
     * latest reported progress: one row per assigned T3 activity or T4 task, never duplicating an
     * imported row matched by (project, T3, T4).
     *
     * @param  list<int>  $projectIds
     */
    public function reconcileProjects(array $projectIds, ?Employee $actor = null): void
    {
        foreach (array_unique(array_map('intval', $projectIds)) as $projectId) {
            if ($projectId > 0) {
                $this->reconcileProject($projectId, $actor);
            }
        }
    }

    private function reconcileProject(int $projectId, ?Employee $actor): void
    {
        $desired = $this->desiredScopes($projectId);
        $existing = $this->existingScopes($projectId);
        $progress = $this->reportedProgress($projectId);
        $actorId = $actor === null ? null : (int) $actor->getKey();
        $actorIdNo = $actor !== null && is_string($actor->id_no) ? $actor->id_no : null;

        $matched = [];
        foreach ($desired as $key => $scope) {
            $row = $existing[$key] ?? null;
            $percent = $progress[$scope['code']] ?? null;
            if ($row === null) {
                $id = (int) $this->connection()->table('project_scopes')->insertGetId(['progress_pct' => $percent]);
                $this->writeScopeLinks($id, $projectId, $scope);
                $this->ledger()->recordAdd(
                    CoreLedger::PRODUCT_PORTAL,
                    'project_scopes:'.$id,
                    self::CORE_TARGET,
                    ['id' => (string) $id],
                    self::CORE_RESOURCE,
                    (string) $id,
                    $actorId,
                    $actorIdNo,
                );

                continue;
            }

            $matched[(int) $row->id] = true;
            $changed = [];
            if ($this->assigneesForScope((int) $row->id) !== $scope['employee_ids']) {
                $this->replaceScopeAssignees((int) $row->id, $scope['employee_ids']);
                $changed['assigneeIds'] = $scope['employee_ids'];
            }
            if ($percent !== null && ($row->progress_pct === null || (float) $row->progress_pct !== (float) $percent)) {
                $this->connection()->table('project_scopes')->where('id', $row->id)->update(['progress_pct' => $percent]);
                $changed['progress_pct'] = $percent;
            }
            if ($changed !== []) {
                $this->ledger()->recordEdit(
                    CoreLedger::PRODUCT_PORTAL,
                    self::CORE_TARGET,
                    ['id' => (string) $row->id, 'fields' => $changed],
                    self::CORE_RESOURCE,
                    (string) $row->id,
                    null,
                    $actorId,
                    $actorIdNo,
                );
            }
        }

        foreach ($existing as $row) {
            if (isset($matched[(int) $row->id])) {
                continue;
            }
            $this->ledger()->recordDelete(
                CoreLedger::PRODUCT_PORTAL,
                self::CORE_TARGET,
                [
                    'id' => (string) $row->id,
                    'airtableRecordId' => is_string($row->airtable_record_id) && $row->airtable_record_id !== '' ? $row->airtable_record_id : null,
                ],
                self::CORE_RESOURCE,
                (string) $row->id,
                $actorId,
                $actorIdNo,
            );
            $this->connection()->table('project_scopes')->where('id', $row->id)->delete();
        }
    }

    /**
     * One entry per assigned scope code that names a T3 activity or a T4 task, keyed by that pair.
     *
     * @return array<string, array{t3_id: ?int, t4_id: ?int, code: string, employee_ids: list<int>}>
     */
    private function desiredScopes(int $projectId): array
    {
        $assignments = $this->connection()->table('projects_scope_assignees')
            ->where('project_id', $projectId)
            ->orderBy('wbs_code')
            ->orderBy('employee_id')
            ->get(['wbs_code', 'employee_id']);
        if ($assignments->isEmpty()) {
            return [];
        }
        $t3 = $this->scopeIds('project_scope_t3_activities');
        $t4 = $this->scopeIds('project_scope_t4_tasks');

        $scopes = [];
        foreach ($assignments as $assignment) {
            $code = $this->scopeCode($assignment->wbs_code);
            if (substr_count($code, '-') + 1 < 3) {
                continue;
            }
            $t4Id = $t4[$code] ?? null;
            $t3Code = $t4Id === null ? $code : substr($code, 0, (int) strrpos($code, '-'));
            $t3Id = $t3[$t3Code] ?? null;
            if ($t3Id === null && $t4Id === null) {
                continue;
            }
            $key = ($t3Id ?? 0).':'.($t4Id ?? 0);
            $scopes[$key] ??= ['t3_id' => $t3Id, 't4_id' => $t4Id, 'code' => $code, 'employee_ids' => []];
            $scopes[$key]['employee_ids'][] = (int) $assignment->employee_id;
        }
        foreach ($scopes as &$scope) {
            $scope['employee_ids'] = array_values(array_unique($scope['employee_ids']));
            sort($scope['employee_ids']);
        }

        return $scopes;
    }

    /**
     * The project's mirrored rows, keyed by their (T3, T4) pair so an imported row is matched, not duplicated.
     *
     * @return array<string, object>
     */
    private function existingScopes(int $projectId): array
    {
        $rows = $this->connection()->table('projects_project_scopes as link')
            ->join('project_scopes as scope', 'scope.id', '=', 'link.project_scope_id')
            ->leftJoin('project_scopes_t3_activities as t3', 't3.project_scope_id', '=', 'scope.id')
            ->leftJoin('project_scopes_t4_tasks as t4', 't4.project_scope_id', '=', 'scope.id')
            ->where('link.project_id', $projectId)
            ->orderBy('scope.id')
            ->get(['scope.id', 'scope.airtable_record_id', 'scope.progress_pct', 't3.t3_activity_id', 't4.t4_task_id']);

        $scopes = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! isset($scopes[$id])) {
                $scopes[$id] = (object) [
                    'id' => $id,
                    'airtable_record_id' => $row->airtable_record_id,
                    'progress_pct' => $row->progress_pct,
                    't3_activity_id' => null,
                    't4_task_id' => null,
                ];
            }
            if ($scopes[$id]->t3_activity_id === null && $row->t3_activity_id !== null) {
                $scopes[$id]->t3_activity_id = (int) $row->t3_activity_id;
            }
            if ($scopes[$id]->t4_task_id === null && $row->t4_task_id !== null) {
                $scopes[$id]->t4_task_id = (int) $row->t4_task_id;
            }
        }

        $byKey = [];
        foreach ($scopes as $scope) {
            $key = ($scope->t3_activity_id ?? 0).':'.($scope->t4_task_id ?? 0);
            $byKey[$key] ??= $scope;
        }

        return $byKey;
    }

    /**
     * The newest reported percent per scope code, whatever its report's approval: Airtable shows what
     * the member last reported, and a later day's figure beats an older one.
     *
     * @return array<string, int>
     */
    private function reportedProgress(int $projectId): array
    {
        $progress = [];
        $rows = $this->connection()->table('projects_scope_progress')
            ->where('project_id', $projectId)
            ->orderByRaw('report_date IS NULL, report_date DESC')
            ->orderByDesc('id')
            ->get(['wbs_code', 'percent']);
        foreach ($rows as $row) {
            $progress[$this->scopeCode($row->wbs_code)] ??= (int) $row->percent;
        }

        return $progress;
    }

    /**
     * Each T3 or T4 row's id by its scope code.
     *
     * @return array<string, int>
     */
    private function scopeIds(string $table): array
    {
        $ids = [];
        foreach ($this->connection()->table($table)->orderBy('id')->get(['id', 'wbs_code']) as $row) {
            $ids[$this->scopeCode($row->wbs_code)] ??= (int) $row->id;
        }

        return $ids;
    }

    private function scopeCode(mixed $raw): string
    {
        $code = trim((string) $raw);

        return str_ends_with($code, '-') ? $code.'01' : $code;
    }

    /**
     * @param  array{t3_id: ?int, t4_id: ?int, code: string, employee_ids: list<int>}  $scope
     */
    private function writeScopeLinks(int $scopeId, int $projectId, array $scope): void
    {
        $this->connection()->table('projects_project_scopes')->insert(['project_id' => $projectId, 'project_scope_id' => $scopeId]);
        if ($scope['t3_id'] !== null) {
            $this->connection()->table('project_scopes_t3_activities')->insert(['project_scope_id' => $scopeId, 't3_activity_id' => $scope['t3_id']]);
        }
        if ($scope['t4_id'] !== null) {
            $this->connection()->table('project_scopes_t4_tasks')->insert(['project_scope_id' => $scopeId, 't4_task_id' => $scope['t4_id']]);
        }
        $this->replaceScopeAssignees($scopeId, $scope['employee_ids']);
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function replaceScopeAssignees(int $scopeId, array $employeeIds): void
    {
        $this->connection()->table('project_scopes_assigned_to')->where('project_scope_id', $scopeId)->delete();
        foreach ($employeeIds as $employeeId) {
            $this->connection()->table('project_scopes_assigned_to')->insert(['project_scope_id' => $scopeId, 'employee_id' => $employeeId]);
        }
    }

    /**
     * @return list<int>
     */
    private function assigneesForScope(int $scopeId): array
    {
        return $this->connection()->table('project_scopes_assigned_to')
            ->where('project_scope_id', $scopeId)
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    private function ledger(): CoreLedger
    {
        return $this->ledger ?? app(CoreLedger::class);
    }

    // The board and the report picker are cached; both show assignments and progress.
    private function bump(): void
    {
        PortalCache::bump();
        (new PortalReportProjects)->bumpCache();
    }

    /**
     * The progress each report carried, for View Reports.
     *
     * @param  list<int>  $reportIds
     * @return array<int, list<array{projectId: string, code: string, percent: int}>>
     */
    public function forReports(array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }
        $byReport = [];
        foreach ($this->connection()->table('projects_scope_progress')->whereIn('user_report_id', $reportIds)->orderBy('id')->get() as $row) {
            $byReport[(int) $row->user_report_id][] = [
                'projectId' => (string) (int) $row->project_id,
                'code' => (string) $row->wbs_code,
                'percent' => (int) $row->percent,
            ];
        }

        return $byReport;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
