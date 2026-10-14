<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Manage Scopes (2026-10-13, user): who is assigned to each of a project's scopes (any level, several
 * each, only the project's own members and support members), and the progress (0-100%) they report on
 * those scopes through their daily and late reports. The latest entry is a scope's current progress;
 * every entry keeps the report it came with, so View Reports shows it.
 */
final class PortalScopeWork
{
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
    public function replaceAssignees(int $projectId, mixed $raw, array $scopeCodes, array $peopleIds): void
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
        $this->connection()->table('projects_scope_assignees')->where('project_id', $projectId)->delete();
        if ($rows !== []) {
            $this->connection()->table('projects_scope_assignees')->insert(array_values($rows));
        }
        $this->bump();
    }

    // A scope taken off the project, or a person off it, takes their assignments along.
    public function prune(int $projectId, array $scopeCodes, array $peopleIds): void
    {
        $query = $this->connection()->table('projects_scope_assignees')->where('project_id', $projectId);
        $query->where(function ($stale) use ($scopeCodes, $peopleIds): void {
            $stale->whereNotIn('wbs_code', $scopeCodes === [] ? [''] : $scopeCodes)
                ->orWhereNotIn('employee_id', $peopleIds === [] ? [0] : $peopleIds);
        })->delete();
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
            ->get(['progress.project_id', 'progress.wbs_code', 'progress.percent', 'user_reports.late_submission', 'user_reports.late_submission_approval', 'employees_user_reports.is_flag', 'employees_user_reports.flag_status']);
        $progress = [];
        foreach ($rows as $row) {
            $status = PortalReportReview::status(
                PortalSubmittedReportPresenter::kind($row->late_submission ?? null),
                $row->is_flag ?? false,
                $row->flag_status ?? null,
                $row->late_submission_approval ?? null,
            );
            if ($status === PortalReportReview::APPROVED) {
                $progress[(int) $row->project_id][(string) $row->wbs_code] ??= (int) $row->percent;
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
