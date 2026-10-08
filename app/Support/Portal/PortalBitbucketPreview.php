<?php

namespace App\Support\Portal;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A dry run of the Bitbucket sync for one repository and one day: the commits that day holds, each file
 * they changed, and a summary per author, read the same way the sync reads and counts them. Nothing is
 * saved, and the repository needs no project link, so it shows whether the token, the day rule and the
 * counting work before anything is wired up.
 */
final class PortalBitbucketPreview
{
    // Newest first, so a day this far back is out of reach; a page wholly older than the day ends it sooner.
    private const MAX_PAGES = 20;

    public function __construct(private readonly PortalBitbucketClient $client) {}

    /**
     * @return array{
     *     repository: string,
     *     day: string,
     *     commits: list<array{hash: string, time: string, authorName: ?string, authorEmail: ?string, message: string, isMerge: bool, lines: array{added: int, removed: int, files: int}, files: list<array{path: string, added: int, removed: int, generated: bool}>}>,
     *     authors: list<array{author: string, employee: string, commits: int, merges: int, added: int, removed: int, files: int}>,
     *     pagesRead: int,
     *     complete: bool
     * }
     */
    public function day(string $repository, string $day, ?string $authorEmail = null): array
    {
        $normalized = PortalProjectRepositories::normalize($repository);
        if ($normalized === null) {
            throw new RuntimeException('That is not a bitbucket.org link or workspace/repository.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new RuntimeException('Give the day as YYYY-MM-DD.');
        }
        [$workspace, $slug] = explode('/', $normalized, 2);
        $author = $authorEmail === null ? null : strtolower(trim($authorEmail));

        $found = [];
        $next = null;
        $pages = 0;
        $complete = false;
        while ($pages < self::MAX_PAGES) {
            $page = $this->client->commitsPage($workspace, $slug, $next);
            $pages++;
            $olderOnly = $page['values'] !== [];
            foreach ($page['values'] as $commit) {
                $read = PortalBitbucketSync::read($commit);
                if ($read === null) {
                    continue;
                }
                if ($read['committedOn'] >= $day) {
                    $olderOnly = false;
                }
                if ($read['committedOn'] === $day && ($author === null || $read['authorEmail'] === $author)) {
                    $found[] = $read;
                }
            }
            $next = $page['next'];
            if ($next === null || $olderOnly) {
                $complete = true;
                break;
            }
        }

        $commits = [];
        foreach ($found as $read) {
            $files = $read['isMerge'] ? [] : $this->client->diffstat($workspace, $slug, $read['hash']);
            $commits[] = [
                'hash' => $read['hash'],
                'time' => Carbon::parse($read['committedAt'], 'UTC')->setTimezone(PortalBitbucketSync::COMPANY_ZONE)->format('H:i'),
                'authorName' => $read['authorName'],
                'authorEmail' => $read['authorEmail'],
                'message' => trim(strtok((string) $read['message'], "\n") ?: ''),
                'isMerge' => $read['isMerge'],
                // A merge is listed but, as in the sync, never counted.
                'lines' => $read['isMerge'] ? ['added' => 0, 'removed' => 0, 'files' => 0] : PortalBitbucketSync::lines($files),
                'files' => array_map(static fn (array $file): array => [...$file, 'generated' => PortalBitbucketSync::skips($file)], $files),
            ];
        }

        return [
            'repository' => $normalized,
            'day' => $day,
            'commits' => $commits,
            'authors' => $this->authors($commits),
            'pagesRead' => $pages,
            'complete' => $complete,
        ];
    }

    /**
     * Totals per author, with the employee their address matches, as the sync would match it.
     *
     * @param  list<array{authorName: ?string, authorEmail: ?string, isMerge: bool, lines: array{added: int, removed: int, files: int}}>  $commits
     * @return list<array{author: string, employee: string, commits: int, merges: int, added: int, removed: int, files: int}>
     */
    private function authors(array $commits): array
    {
        $emails = array_values(array_unique(array_filter(array_column($commits, 'authorEmail'))));
        $employees = $emails === [] ? collect() : DB::connection('portal')->table('employees')
            ->whereIn(DB::connection('portal')->raw('LOWER(TRIM(email))'), $emails)
            ->orderBy('id')
            ->get(['id', 'email', 'first_name', 'last_name'])
            ->keyBy(static fn (object $row): string => strtolower(trim((string) $row->email)));

        $totals = [];
        foreach ($commits as $commit) {
            $key = $commit['authorEmail'] ?? '';
            $employee = $employees->get($key);
            $totals[$key] ??= [
                'author' => trim(($commit['authorName'] ?? '').' <'.($commit['authorEmail'] ?? 'no email').'>'),
                'employee' => $employee === null ? 'Unknown author' : trim($employee->first_name.' '.$employee->last_name).' (#'.$employee->id.')',
                'commits' => 0, 'merges' => 0, 'added' => 0, 'removed' => 0, 'files' => 0,
            ];
            $commit['isMerge'] ? $totals[$key]['merges']++ : $totals[$key]['commits']++;
            $totals[$key]['added'] += $commit['lines']['added'];
            $totals[$key]['removed'] += $commit['lines']['removed'];
            $totals[$key]['files'] += $commit['lines']['files'];
        }

        return array_values($totals);
    }
}
