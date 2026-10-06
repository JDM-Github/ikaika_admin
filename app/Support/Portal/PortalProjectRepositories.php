<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The Bitbucket repositories an MIS project tracks its code from (view-projects-dashboard-plan.md, step 7).
 * The Add/Edit form sends them as workspace/repository or a bitbucket.org link; each is stored once in
 * bitbucket_repositories, whatever projects it serves, and linked through projects_bitbucket_repositories.
 * Only MIS carries them: saving any other department's project clears its links.
 */
final class PortalProjectRepositories
{
    public const MAX_PER_PROJECT = 20;

    private const DEPARTMENT = 'mis';

    // Bitbucket's own characters for a workspace ID and a repository slug.
    private const PART = '[A-Za-z0-9._-]+';

    public static function appliesTo(mixed $department): bool
    {
        return strtolower(trim((string) $department)) === self::DEPARTMENT;
    }

    /**
     * Each value as workspace/repository, lower case, once; a value that names no repository is refused.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function parse(array $values): array
    {
        $parsed = [];
        foreach ($values as $value) {
            $text = trim((string) (is_scalar($value) ? $value : ''));
            if ($text === '') {
                continue;
            }
            $repository = self::normalize($text);
            if ($repository === null) {
                abort(422, 'Enter each repository as workspace/repository or its bitbucket.org link.');
            }
            $parsed[$repository] = true;
        }
        if (count($parsed) > self::MAX_PER_PROJECT) {
            abort(422, 'A project can track at most '.self::MAX_PER_PROJECT.' repositories.');
        }

        return array_keys($parsed);
    }

    // workspace/repository from that form itself, an https link to bitbucket.org, or an SSH clone address.
    public static function normalize(string $text): ?string
    {
        $text = preg_replace('#(\.git)?/*$#i', '', trim($text)) ?? '';
        $patterns = [
            '#^https?://(?:[^@/]+@)?(?:www\.)?bitbucket\.org/('.self::PART.')/('.self::PART.')(?:[/?\#].*)?$#i',
            '#^git@bitbucket\.org:('.self::PART.')/('.self::PART.')$#i',
            '#^('.self::PART.')/('.self::PART.')$#',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return strtolower($match[1].'/'.$match[2]);
            }
        }

        return null;
    }

    /**
     * The project's repositories as workspace/repository, sorted.
     *
     * @return list<string>
     */
    public function forProject(int $projectId): array
    {
        return $this->connection()
            ->table('projects_bitbucket_repositories as links')
            ->join('bitbucket_repositories as repositories', 'repositories.id', '=', 'links.repository_id')
            ->where('links.project_id', $projectId)
            ->orderBy('repositories.workspace')
            ->orderBy('repositories.repo_slug')
            ->get(['repositories.workspace', 'repositories.repo_slug'])
            ->map(static fn (object $row): string => $row->workspace.'/'.$row->repo_slug)
            ->all();
    }

    /**
     * Links exactly these repositories to the project, adding any repository not yet known. A
     * repository no project links any more stays, with its commits, for when one links it again.
     *
     * @param  list<string>  $repositories  workspace/repository, as parse() returns them
     */
    public function replace(int $projectId, array $repositories): void
    {
        $connection = $this->connection();
        $connection->table('projects_bitbucket_repositories')->where('project_id', $projectId)->delete();
        if ($repositories === []) {
            return;
        }

        $pairs = array_map(static function (string $repository): array {
            [$workspace, $slug] = explode('/', $repository, 2);

            return ['workspace' => $workspace, 'repo_slug' => $slug];
        }, $repositories);
        $connection->table('bitbucket_repositories')->insertOrIgnore($pairs);

        $ids = [];
        foreach ($pairs as $pair) {
            $ids[] = (int) $connection->table('bitbucket_repositories')->where($pair)->value('id');
        }
        $connection->table('projects_bitbucket_repositories')->insert(array_map(
            static fn (int $id): array => ['project_id' => $projectId, 'repository_id' => $id],
            $ids,
        ));
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
