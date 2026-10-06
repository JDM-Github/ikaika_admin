<?php

namespace App\Support\Portal;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Reads the commits of every repository a project links (view-projects-dashboard-plan.md, step 7) in two
 * passes. Listing stores each new commit once with its author, its day in company time and whether it is
 * a merge; counting fills lines added and removed from its diffstat, a batch per run, so a long history
 * never spends the rate limit in one go. Merges are stored but never counted, and generated or vendored
 * files never count, so a regenerated lock file cannot outweigh a week of work.
 */
final class PortalBitbucketSync
{
    // The day a report reads is the company's (the zone project numbers use), whoever pushed and when.
    private const COMPANY_ZONE = 'Asia/Manila';

    private const COUNT_PER_RUN = 300;

    private const GENERATED = [
        '#(^|/)(package-lock\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb|composer\.lock|Gemfile\.lock|Podfile\.lock)$#i',
        '#(^|/)(vendor|node_modules|graphify-out|dist|build|coverage|\.expo)/#i',
        '#\.(min\.js|min\.css|map)$#i',
    ];

    public function __construct(private readonly PortalBitbucketClient $client) {}

    /**
     * Every repository at least one project links, each on its own: one failing never stops the rest.
     *
     * @return array<string, array{listed: int, counted: int}|string> workspace/repository => counts, or its error
     */
    public function syncLinked(): array
    {
        $outcomes = [];
        $repositories = $this->connection()->table('bitbucket_repositories')
            ->whereExists(fn ($linked) => $linked->from('projects_bitbucket_repositories')->whereColumn('repository_id', 'bitbucket_repositories.id'))
            ->orderBy('id')
            ->get();
        foreach ($repositories as $repository) {
            $outcomes[$repository->workspace.'/'.$repository->repo_slug] = $this->sync($repository);
        }

        return $outcomes;
    }

    /**
     * @return array{listed: int, counted: int}|string the counts, or the error it stopped on
     */
    public function sync(object $repository): array|string
    {
        $id = (int) $repository->id;
        try {
            $listed = $this->list($repository);
            $counted = $this->count($repository);
        } catch (Throwable $error) {
            // A client failure says only Bitbucket's status and message; anything else stays generic.
            $message = $error instanceof RuntimeException ? mb_substr($error->getMessage(), 0, 255) : 'The sync failed.';
            $this->connection()->table('bitbucket_repositories')->where('id', $id)->update(['last_sync_error' => $message]);

            return $message;
        }
        $this->connection()->table('bitbucket_repositories')->where('id', $id)->update([
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        return ['listed' => $listed, 'counted' => $counted];
    }

    // Stores the commits not yet stored; once a listing has reached the first commit, a page holding nothing new ends it.
    private function list(object $repository): int
    {
        $id = (int) $repository->id;
        $complete = (bool) $repository->history_complete;
        $employees = $this->employeesByEmail();
        $listed = 0;
        $next = null;
        do {
            $page = $this->client->commitsPage($repository->workspace, $repository->repo_slug, $next);
            $rows = [];
            foreach ($page['values'] as $commit) {
                $row = $this->row($id, $commit, $employees);
                if ($row !== null) {
                    $rows[$row['hash']] = $row;
                }
            }
            $known = $rows === [] ? [] : $this->connection()->table('bitbucket_commits')
                ->where('repository_id', $id)->whereIn('hash', array_keys($rows))->pluck('hash')->all();
            $new = array_diff_key($rows, array_flip($known));
            if ($new !== []) {
                $this->connection()->table('bitbucket_commits')->insertOrIgnore(array_values($new));
                $listed += count($new);
            }
            if ($complete && $new === [] && $rows !== []) {
                return $listed;
            }
            $next = $page['next'];
        } while ($next !== null);

        $this->connection()->table('bitbucket_repositories')->where('id', $id)->update(['history_complete' => true]);

        return $listed;
    }

    // Lines for the newest commits still uncounted, at most a batch per run.
    private function count(object $repository): int
    {
        $pending = $this->connection()->table('bitbucket_commits')
            ->where('repository_id', (int) $repository->id)
            ->whereNull('counted_at')
            ->orderByDesc('committed_at')
            ->limit(self::COUNT_PER_RUN)
            ->pluck('hash');
        foreach ($pending as $hash) {
            $added = 0;
            $removed = 0;
            $files = 0;
            foreach ($this->client->diffstat($repository->workspace, $repository->repo_slug, (string) $hash) as $file) {
                if (self::isGenerated($file['path'])) {
                    continue;
                }
                $added += $file['added'];
                $removed += $file['removed'];
                $files++;
            }
            $this->connection()->table('bitbucket_commits')
                ->where('repository_id', (int) $repository->id)->where('hash', $hash)
                ->update(['lines_added' => $added, 'lines_removed' => $removed, 'files_changed' => $files, 'counted_at' => now()]);
        }

        return count($pending);
    }

    /**
     * One commit as a row, or null when Bitbucket sent no usable hash or date.
     *
     * @param  array<string, mixed>  $commit
     * @param  array<string, int>  $employees
     * @return array<string, mixed>|null
     */
    private function row(int $repositoryId, array $commit, array $employees): ?array
    {
        $hash = $commit['hash'] ?? null;
        $date = $commit['date'] ?? null;
        if (! is_string($hash) || preg_match('/^[0-9a-f]{40}$/', $hash) !== 1 || ! is_string($date)) {
            return null;
        }
        try {
            $moment = Carbon::parse($date);
        } catch (Throwable) {
            return null;
        }

        $raw = is_string($commit['author']['raw'] ?? null) ? trim($commit['author']['raw']) : '';
        [$name, $email] = preg_match('/^(.*?)\s*<([^<>]*)>$/', $raw, $match) === 1
            ? [trim($match[1]), strtolower(trim($match[2]))]
            : [$raw, ''];
        $parents = is_array($commit['parents'] ?? null) ? count($commit['parents']) : 0;
        $isMerge = $parents > 1;
        $message = is_string($commit['message'] ?? null) ? mb_substr($commit['message'], 0, 60000) : null;

        return [
            'repository_id' => $repositoryId,
            'hash' => $hash,
            'author_name' => $name === '' ? null : mb_substr($name, 0, 255),
            'author_email' => $email === '' ? null : mb_substr($email, 0, 255),
            'employee_id' => $email === '' ? null : ($employees[$email] ?? null),
            'committed_at' => $moment->copy()->utc()->format('Y-m-d H:i:s'),
            'committed_on' => $moment->copy()->setTimezone(self::COMPANY_ZONE)->toDateString(),
            'message' => $message,
            'is_merge' => $isMerge,
            // A merge repeats its parents' lines, so it is never counted and never fetched.
            'counted_at' => $isMerge ? now() : null,
        ];
    }

    public static function isGenerated(string $path): bool
    {
        foreach (self::GENERATED as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every employee's address, lower case, to the first employee holding it.
     *
     * @return array<string, int>
     */
    private function employeesByEmail(): array
    {
        $byEmail = [];
        $rows = $this->connection()->table('employees')->whereNotNull('email')->where('email', '<>', '')->orderBy('id')->get(['id', 'email']);
        foreach ($rows as $row) {
            $byEmail[strtolower(trim((string) $row->email))] ??= (int) $row->id;
        }

        return $byEmail;
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
