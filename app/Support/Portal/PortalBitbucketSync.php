<?php

namespace App\Support\Portal;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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
    public const COMPANY_ZONE = 'Asia/Manila';

    public const COUNT_PER_RUN = 300;

    // Long enough for a full run; a crashed run frees the repository again once it lapses.
    private const LOCK_SECONDS = 900;

    private const GENERATED = [
        '#(^|/)(package-lock\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb|composer\.lock|Gemfile\.lock|Podfile\.lock)$#i',
        '#(^|/)(vendor|node_modules|graphify-out|dist|build|coverage|\.expo)/#i',
        '#\.(min\.js|min\.css|map)$#i',
        // Data rather than code: seed dumps and migration exports, regenerated whole each time.
        '#(^|/)data\.sql$#i',
        '#(^|/)(outputs|exports?)/#i',
        // Copied-in tooling and the Flutter original the portal was ported from.
        '#(^|/)(\.claude|reference)/#i',
    ];

    // ponytail: a size heuristic; one file changing more than this in a commit is taken as generated
    // (a dump or an export no pattern names). Name the path in GENERATED instead when one turns up.
    private const MAX_FILE_LINES = 5000;

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
     * One repository, never twice at once: a push's sync and the hourly one take turns.
     *
     * @return array{listed: int, counted: int}|string the counts, or why it stopped
     */
    public function sync(object $repository, int $countLimit = self::COUNT_PER_RUN): array|string
    {
        $id = (int) $repository->id;
        $lock = Cache::lock('portal-bitbucket-sync:'.$id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return 'Already syncing.';
        }
        try {
            $listed = $this->list($repository);
            $counted = $this->count($repository, $countLimit);
        } catch (Throwable $error) {
            // A client failure says only Bitbucket's status and message; anything else stays generic.
            $message = match (true) {
                $error instanceof RuntimeException => mb_substr($error->getMessage(), 0, 255),
                // Still timing out after the client's retries: say so, rather than a bare failure.
                $error instanceof ConnectionException => 'Bitbucket did not answer in time; the next run picks up where this stopped.',
                default => 'The sync failed.',
            };
            $this->connection()->table('bitbucket_repositories')->where('id', $id)->update(['last_sync_error' => $message]);

            return $message;
        } finally {
            $lock->release();
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
    private function count(object $repository, int $limit): int
    {
        $pending = $this->connection()->table('bitbucket_commits')
            ->where('repository_id', (int) $repository->id)
            ->whereNull('counted_at')
            ->orderByDesc('committed_at')
            ->limit($limit)
            ->pluck('hash');
        foreach ($pending as $hash) {
            $lines = self::lines($this->client->diffstat($repository->workspace, $repository->repo_slug, (string) $hash));
            $this->connection()->table('bitbucket_commits')
                ->where('repository_id', (int) $repository->id)->where('hash', $hash)
                ->update(['lines_added' => $lines['added'], 'lines_removed' => $lines['removed'], 'files_changed' => $lines['files'], 'counted_at' => now()]);
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
        $read = self::read($commit);
        if ($read === null) {
            return null;
        }

        return [
            'repository_id' => $repositoryId,
            'hash' => $read['hash'],
            'author_name' => $read['authorName'],
            'author_email' => $read['authorEmail'],
            'employee_id' => $read['authorEmail'] === null ? null : ($employees[$read['authorEmail']] ?? null),
            'committed_at' => $read['committedAt'],
            'committed_on' => $read['committedOn'],
            'message' => $read['message'],
            'is_merge' => $read['isMerge'],
            // A merge repeats its parents' lines, so it is never counted and never fetched.
            'counted_at' => $read['isMerge'] ? now() : null,
        ];
    }

    /**
     * What a Bitbucket commit says, read the way the sync stores it (the preview command reads it the same
     * way), or null when it has no usable hash or date.
     *
     * @param  array<string, mixed>  $commit
     * @return array{hash: string, authorName: ?string, authorEmail: ?string, committedAt: string, committedOn: string, message: ?string, isMerge: bool}|null
     */
    public static function read(array $commit): ?array
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

        return [
            'hash' => $hash,
            'authorName' => $name === '' ? null : mb_substr($name, 0, 255),
            'authorEmail' => $email === '' ? null : mb_substr($email, 0, 255),
            'committedAt' => $moment->copy()->utc()->format('Y-m-d H:i:s'),
            'committedOn' => $moment->copy()->setTimezone(self::COMPANY_ZONE)->toDateString(),
            'message' => is_string($commit['message'] ?? null) ? mb_substr($commit['message'], 0, 60000) : null,
            'isMerge' => $parents > 1,
        ];
    }

    /**
     * A commit's lines and files, generated and vendored files left out.
     *
     * @param  list<array{path: string, added: int, removed: int}>  $files
     * @return array{added: int, removed: int, files: int}
     */
    public static function lines(array $files): array
    {
        $lines = ['added' => 0, 'removed' => 0, 'files' => 0];
        foreach ($files as $file) {
            if (self::skips($file)) {
                continue;
            }
            $lines['added'] += $file['added'];
            $lines['removed'] += $file['removed'];
            $lines['files']++;
        }

        return $lines;
    }

    // Today's calendar day in company time, the day a Daily Report covers.
    public static function companyToday(): string
    {
        return Carbon::now(self::COMPANY_ZONE)->toDateString();
    }

    /**
     * Whether a changed file stays out of the counts: a generated path, or one too large to be written by hand.
     *
     * @param  array{path: string, added: int, removed: int}  $file
     */
    public static function skips(array $file): bool
    {
        return self::isGenerated($file['path']) || $file['added'] + $file['removed'] > self::MAX_FILE_LINES;
    }

    /**
     * Every commit of a linked repository counted again on the next runs, after the counting rules change;
     * merges stay uncounted, and an unlinked repository is never synced, so it is left alone.
     */
    public function recount(): int
    {
        return $this->connection()->table('bitbucket_commits')
            ->where('is_merge', false)
            ->whereIn('repository_id', fn ($linked) => $linked->select('repository_id')->from('projects_bitbucket_repositories'))
            ->update(['counted_at' => null]);
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
