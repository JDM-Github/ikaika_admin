<?php

namespace App\Support\Portal;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What one person committed to one project's repositories on one day, by the commit's own day in company
 * time. A Daily Report reads today's, a Late Report the day it is for, so a report filed late still shows
 * the work of the day it covers. It is read when asked, never copied, so a commit pushed after the report
 * was filed still lands on the day it was written. Merges never count.
 */
final class PortalProjectCodeActivity
{
    // A report shows at most this many commits per project; the totals still count every one.
    private const LISTED = 20;

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

    /**
     * One person's code on one day for every project its repositories are linked to, what the Daily and
     * Late Report forms show beside each project. A commit in a repository two projects share shows on both.
     *
     * @return list<array{projectId: string, commits: int, linesAdded: int, linesRemoved: int, filesChanged: int, uncounted: int, entries: list<array{hash: string, time: string, repository: string, message: string, linesAdded: int, linesRemoved: int}>}>
     */
    public function forPersonDay(int $employeeId, string $day): array
    {
        $rows = DB::connection('portal')->table('bitbucket_commits as commits')
            ->join('projects_bitbucket_repositories as links', 'links.repository_id', '=', 'commits.repository_id')
            ->join('bitbucket_repositories as repositories', 'repositories.id', '=', 'commits.repository_id')
            ->where('commits.employee_id', $employeeId)
            ->where('commits.committed_on', $day)
            ->where('commits.is_merge', false)
            ->orderByDesc('commits.committed_at')
            ->get([
                'links.project_id', 'commits.hash', 'commits.committed_at', 'commits.message', 'commits.counted_at',
                'commits.lines_added', 'commits.lines_removed', 'commits.files_changed', 'repositories.repo_slug',
            ]);

        $projects = [];
        foreach ($rows as $row) {
            $id = (string) (int) $row->project_id;
            $projects[$id] ??= ['projectId' => $id, 'commits' => 0, 'linesAdded' => 0, 'linesRemoved' => 0, 'filesChanged' => 0, 'uncounted' => 0, 'entries' => []];
            $project = &$projects[$id];
            $project['commits']++;
            $project['linesAdded'] += (int) $row->lines_added;
            $project['linesRemoved'] += (int) $row->lines_removed;
            $project['filesChanged'] += (int) $row->files_changed;
            $project['uncounted'] += $row->counted_at === null ? 1 : 0;
            if (count($project['entries']) < self::LISTED) {
                $project['entries'][] = [
                    'hash' => substr((string) $row->hash, 0, 7),
                    'time' => Carbon::parse((string) $row->committed_at, 'UTC')->setTimezone(PortalBitbucketSync::COMPANY_ZONE)->format('H:i'),
                    'repository' => (string) $row->repo_slug,
                    'message' => trim(strtok((string) $row->message, "\n") ?: ''),
                    'linesAdded' => (int) $row->lines_added,
                    'linesRemoved' => (int) $row->lines_removed,
                ];
            }
            unset($project);
        }

        return array_values($projects);
    }

    /**
     * Each project's code over its whole history, for the View Projects dashboard: only projects that link
     * a repository appear, so another project shows no code tiles at all. Merges never count.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array{repositories: int, commits: int, linesAdded: int, linesRemoved: int, filesChanged: int, activeDays: int, contributors: int, lastCommitOn: ?string, uncounted: int}>
     */
    public function summariesByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $connection = DB::connection('portal');
        $repositories = $connection->table('projects_bitbucket_repositories')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, COUNT(*) as repositories')
            ->groupBy('project_id')
            ->pluck('repositories', 'project_id');
        if ($repositories->isEmpty()) {
            return [];
        }
        $totals = $connection->table('projects_bitbucket_repositories as links')
            ->join('bitbucket_commits as commits', 'commits.repository_id', '=', 'links.repository_id')
            ->whereIn('links.project_id', $repositories->keys()->all())
            ->where('commits.is_merge', false)
            ->selectRaw('links.project_id, COUNT(*) as commits')
            ->selectRaw('COALESCE(SUM(commits.lines_added), 0) as lines_added')
            ->selectRaw('COALESCE(SUM(commits.lines_removed), 0) as lines_removed')
            ->selectRaw('COALESCE(SUM(commits.files_changed), 0) as files_changed')
            ->selectRaw('COUNT(DISTINCT commits.committed_on) as active_days')
            ->selectRaw('COUNT(DISTINCT COALESCE(commits.employee_id, commits.author_email)) as contributors')
            ->selectRaw('MAX(commits.committed_on) as last_commit_on')
            ->selectRaw('SUM(CASE WHEN commits.counted_at IS NULL THEN 1 ELSE 0 END) as uncounted')
            ->groupBy('links.project_id')
            ->get()
            ->keyBy('project_id');

        $summaries = [];
        foreach ($repositories as $projectId => $count) {
            $row = $totals->get($projectId);
            $summaries[(int) $projectId] = [
                'repositories' => (int) $count,
                'commits' => (int) ($row->commits ?? 0),
                'linesAdded' => (int) ($row->lines_added ?? 0),
                'linesRemoved' => (int) ($row->lines_removed ?? 0),
                'filesChanged' => (int) ($row->files_changed ?? 0),
                'activeDays' => (int) ($row->active_days ?? 0),
                'contributors' => (int) ($row->contributors ?? 0),
                'lastCommitOn' => $row?->last_commit_on === null ? null : (string) $row->last_commit_on,
                'uncounted' => (int) ($row->uncounted ?? 0),
            ];
        }

        return $summaries;
    }

    /**
     * A project's code by person and day, for its Activity tab: each person's totals, and each person's
     * day so a report row can show the code of the day it covers. Unmatched authors and merges never count.
     *
     * @return array{people: array<int, array{commits: int, linesAdded: int, linesRemoved: int, lastCommitOn: string}>, days: array<string, array{commits: int, linesAdded: int, linesRemoved: int}>}
     */
    public function byPersonForProject(int $projectId): array
    {
        $rows = DB::connection('portal')->table('bitbucket_commits as commits')
            ->whereIn('commits.repository_id', fn ($linked) => $linked->select('repository_id')
                ->from('projects_bitbucket_repositories')->where('project_id', $projectId))
            ->whereNotNull('commits.employee_id')
            ->where('commits.is_merge', false)
            ->groupBy('commits.employee_id', 'commits.committed_on')
            ->selectRaw('commits.employee_id, commits.committed_on, COUNT(*) as commits')
            ->selectRaw('COALESCE(SUM(commits.lines_added), 0) as lines_added')
            ->selectRaw('COALESCE(SUM(commits.lines_removed), 0) as lines_removed')
            ->get();

        $people = [];
        $days = [];
        foreach ($rows as $row) {
            $id = (int) $row->employee_id;
            $day = (string) $row->committed_on;
            $days[$id.'|'.$day] = ['commits' => (int) $row->commits, 'linesAdded' => (int) $row->lines_added, 'linesRemoved' => (int) $row->lines_removed];
            $people[$id] ??= ['commits' => 0, 'linesAdded' => 0, 'linesRemoved' => 0, 'lastCommitOn' => $day];
            $people[$id]['commits'] += (int) $row->commits;
            $people[$id]['linesAdded'] += (int) $row->lines_added;
            $people[$id]['linesRemoved'] += (int) $row->lines_removed;
            $people[$id]['lastCommitOn'] = max($people[$id]['lastCommitOn'], $day);
        }

        return ['people' => $people, 'days' => $days];
    }
}
