<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\DB;

/**
 * What one person committed to one project's repositories on one day, by the commit's own day in company
 * time. A Daily Report reads today's, a Late Report the day it is for, so a report filed late still shows
 * the work of the day it covers. It is read when asked, never copied, so a commit pushed after the report
 * was filed still lands on the day it was written. Merges never count.
 */
final class PortalProjectCodeActivity
{
    /**
     * @return array{commits: int, linesAdded: int, linesRemoved: int, filesChanged: int, uncounted: int}
     */
    public function forDay(int $projectId, int $employeeId, string $day): array
    {
        $totals = DB::connection('portal')->table('bitbucket_commits as commits')
            ->whereIn('commits.repository_id', fn ($linked) => $linked->select('repository_id')
                ->from('projects_bitbucket_repositories')->where('project_id', $projectId))
            ->where('commits.employee_id', $employeeId)
            ->where('commits.committed_on', $day)
            ->where('commits.is_merge', false)
            ->selectRaw('COUNT(*) as commits')
            ->selectRaw('COALESCE(SUM(commits.lines_added), 0) as lines_added')
            ->selectRaw('COALESCE(SUM(commits.lines_removed), 0) as lines_removed')
            ->selectRaw('COALESCE(SUM(commits.files_changed), 0) as files_changed')
            ->selectRaw('SUM(CASE WHEN commits.counted_at IS NULL THEN 1 ELSE 0 END) as uncounted')
            ->first();

        return [
            'commits' => (int) ($totals->commits ?? 0),
            'linesAdded' => (int) ($totals->lines_added ?? 0),
            'linesRemoved' => (int) ($totals->lines_removed ?? 0),
            'filesChanged' => (int) ($totals->files_changed ?? 0),
            // Commits whose lines are still being fetched; their counts join on a later run.
            'uncounted' => (int) ($totals->uncounted ?? 0),
        ];
    }
}
