<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Bitbucket tables (step 7 of the View Projects dashboard plan): a repository is stored once
 * whatever projects it serves, a commit once per repository, and deleting a project unlinks its
 * repositories without losing their commits.
 */
class PortalBitbucketTablesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private function insertProject(string $name): int
    {
        return (int) DB::connection('portal')->table('projects')->insertGetId([
            'project_name' => $name,
            'department' => 'MIS',
            'status' => 'Started',
            'project_creation_status' => 'Approved',
        ]);
    }

    public function test_a_repository_serves_several_projects_and_keeps_each_commit_once(): void
    {
        $portal = DB::connection('portal');
        $first = $this->insertProject('Bitbucket Fixture A 2020');
        $second = $this->insertProject('Bitbucket Fixture B 2020');
        $repository = (int) $portal->table('bitbucket_repositories')->insertGetId([
            'workspace' => 'ikaika-web',
            'repo_slug' => 'fixture-repository-2020',
        ]);
        $portal->table('projects_bitbucket_repositories')->insert([
            ['project_id' => $first, 'repository_id' => $repository],
            ['project_id' => $second, 'repository_id' => $repository],
        ]);
        $portal->table('bitbucket_commits')->insert([
            'repository_id' => $repository,
            'hash' => str_repeat('a', 40),
            'author_email' => 'nobody@example.com',
            'committed_at' => '2020-01-06 01:00:00',
            'lines_added' => 12,
            'lines_removed' => 3,
            'files_changed' => 2,
        ]);

        $commit = $portal->table('bitbucket_commits')->where('repository_id', $repository)->first();
        $this->assertNotNull($commit);
        $this->assertNull($commit->employee_id);
        $this->assertSame(0, (int) $commit->is_merge);

        // Deleting a project only unlinks it; the repository and its commits stay for the other one.
        $portal->table('projects')->where('id', $first)->delete();
        $this->assertSame(1, $portal->table('projects_bitbucket_repositories')->where('repository_id', $repository)->count());
        $this->assertSame(1, $portal->table('bitbucket_commits')->where('repository_id', $repository)->count());

        // Dropping the repository takes its links and commits with it.
        $portal->table('bitbucket_repositories')->where('id', $repository)->delete();
        $this->assertSame(0, $portal->table('projects_bitbucket_repositories')->where('repository_id', $repository)->count());
        $this->assertSame(0, $portal->table('bitbucket_commits')->where('repository_id', $repository)->count());
    }

    public function test_a_repository_and_a_commit_are_never_stored_twice(): void
    {
        $portal = DB::connection('portal');
        $repository = (int) $portal->table('bitbucket_repositories')->insertGetId(['workspace' => 'ikaika-web', 'repo_slug' => 'fixture-unique-2020']);
        $commit = ['repository_id' => $repository, 'hash' => str_repeat('b', 40), 'committed_at' => '2020-01-06 01:00:00'];
        $portal->table('bitbucket_commits')->insert($commit);

        $this->assertThrows(fn () => $portal->table('bitbucket_commits')->insert($commit), QueryException::class);
        $this->assertThrows(
            fn () => $portal->table('bitbucket_repositories')->insert(['workspace' => 'ikaika-web', 'repo_slug' => 'fixture-unique-2020']),
            QueryException::class,
        );
    }
}
