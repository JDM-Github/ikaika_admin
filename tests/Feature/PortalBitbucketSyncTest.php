<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalBitbucketSync;
use App\Support\Portal\PortalProjectCodeActivity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * Bitbucket step 3 against faked responses only: commits are listed once with their day in company time,
 * merges and generated files never count, a report's day reads that day's work alone, a finished history
 * stops at the first page with nothing new, and a failure keeps no token.
 */
class PortalBitbucketSyncTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const API = 'https://api.bitbucket.org/2.0/repositories/ikaika-web/fixture-sync-2020';

    private const TOKEN = 'fixture-token-never-logged';

    private int $projectId;

    private int $repositoryId;

    private object $developer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bitbucket.token' => self::TOKEN]);
        $portal = DB::connection('portal');
        // Only this test's repository is linked (inside the transaction), so real links are never synced here.
        $portal->table('projects_bitbucket_repositories')->delete();
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'developer_modes'] as $table) {
            $portal->table($table)->delete();
        }
        // An active member who can sign in, whose preset files Daily Reports, so the report endpoint can be asked as them.
        $this->developer = $portal->table('employees')->whereNotNull('email')->where('email', 'like', '%@%')
            ->whereNotNull('id_no')->where('id_no', '!=', '')->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->orderBy('id')->first(['id', 'email', 'id_no']);
        $this->projectId = (int) $portal->table('projects')->insertGetId([
            'project_name' => 'Bitbucket Sync Fixture 2020',
            'department' => 'MIS',
            'status' => 'Started',
            'project_creation_status' => 'Approved',
        ]);
        $this->repositoryId = (int) $portal->table('bitbucket_repositories')->insertGetId(['workspace' => 'ikaika-web', 'repo_slug' => 'fixture-sync-2020']);
        $portal->table('projects_bitbucket_repositories')->insert(['project_id' => $this->projectId, 'repository_id' => $this->repositoryId]);
    }

    /**
     * @param  list<string>  $parents
     * @return array<string, mixed>
     */
    private function commit(string $hash, string $date, string $author, array $parents = ['p']): array
    {
        return [
            'hash' => $hash,
            'date' => $date,
            'author' => ['raw' => $author],
            'message' => 'Work '.$hash[0],
            'parents' => array_map(static fn (string $parent): array => ['hash' => $parent], $parents),
        ];
    }

    private function fakeBitbucket(): void
    {
        $mine = 'Dev <'.strtoupper((string) $this->developer->email).'>';
        Http::fake(function (Request $request) use ($mine) {
            $url = $request->url();
            if (str_starts_with($url, self::API.'/commits')) {
                if (str_contains($url, 'page=2')) {
                    return Http::response(['values' => [$this->commit(str_repeat('d', 40), '2020-01-03T09:00:00+08:00', $mine)]]);
                }

                return Http::response([
                    'values' => [
                        // 17:30 UTC on the 5th is 01:30 on the 6th in Manila: it is the 6th's work.
                        $this->commit(str_repeat('a', 40), '2020-01-05T17:30:00+00:00', $mine),
                        $this->commit(str_repeat('b', 40), '2020-01-05T10:00:00+00:00', $mine, ['p', 'q']),
                        $this->commit(str_repeat('c', 40), '2020-01-05T09:00:00+08:00', 'Stranger <stranger@example.org>'),
                        ['hash' => 'not-a-hash', 'date' => '2020-01-05T09:00:00+08:00'],
                    ],
                    'next' => self::API.'/commits?pagelen=100&page=2',
                ]);
            }
            if (str_starts_with($url, self::API.'/diffstat/')) {
                return Http::response(['values' => [
                    ['lines_added' => 10, 'lines_removed' => 2, 'new' => ['path' => 'src/app.ts']],
                    ['lines_added' => 500, 'lines_removed' => 400, 'new' => ['path' => 'package-lock.json']],
                    ['lines_added' => 7, 'lines_removed' => 0, 'new' => ['path' => 'vendor/lib/x.php']],
                    ['lines_added' => 0, 'lines_removed' => 3, 'old' => ['path' => 'src/old.ts'], 'new' => null],
                ]]);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 404);
        });
    }

    public function test_commits_are_stored_once_with_their_company_day_and_honest_lines(): void
    {
        $this->fakeBitbucket();

        $outcome = app(PortalBitbucketSync::class)->syncLinked();

        $this->assertSame(['listed' => 4, 'counted' => 3], $outcome['ikaika-web/fixture-sync-2020']);
        $commits = DB::connection('portal')->table('bitbucket_commits')->where('repository_id', $this->repositoryId)->get()->keyBy('hash');
        $mine = $commits[str_repeat('a', 40)];
        $this->assertSame('2020-01-06', $mine->committed_on);
        $this->assertSame('2020-01-05 17:30:00', $mine->committed_at);
        $this->assertSame((int) $this->developer->id, (int) $mine->employee_id);
        $this->assertSame([10, 5, 2], [(int) $mine->lines_added, (int) $mine->lines_removed, (int) $mine->files_changed]);

        $merge = $commits[str_repeat('b', 40)];
        $this->assertSame(1, (int) $merge->is_merge);
        $this->assertSame(0, (int) $merge->lines_added);
        $this->assertNotNull($merge->counted_at);
        $this->assertNull($commits[str_repeat('c', 40)]->employee_id);
        $this->assertSame('stranger@example.org', $commits[str_repeat('c', 40)]->author_email);

        $repository = DB::connection('portal')->table('bitbucket_repositories')->where('id', $this->repositoryId)->first();
        $this->assertSame(1, (int) $repository->history_complete);
        $this->assertNotNull($repository->last_synced_at);
        // A merge's lines are never fetched.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/diffstat/'.str_repeat('b', 40)));
    }

    // A Daily Report for the 6th reads the 6th's work; a Late Report for the 3rd reads the 3rd's, never another day's.
    public function test_a_report_day_reads_only_that_days_commits(): void
    {
        $this->fakeBitbucket();
        app(PortalBitbucketSync::class)->syncLinked();
        $activity = app(PortalProjectCodeActivity::class);
        $developer = (int) $this->developer->id;

        $this->assertSame(['commits' => 1, 'linesAdded' => 10, 'linesRemoved' => 5, 'filesChanged' => 2, 'uncounted' => 0], $activity->forDay($this->projectId, $developer, '2020-01-06'));
        $this->assertSame(1, $activity->forDay($this->projectId, $developer, '2020-01-03')['commits']);
        $this->assertSame(0, $activity->forDay($this->projectId, $developer, '2020-01-05')['commits']);
        $this->assertSame(0, $activity->forDay($this->projectId + 1, $developer, '2020-01-06')['commits']);
    }

    // Once the whole history is in, a page holding nothing new ends the listing.
    public function test_a_finished_history_stops_at_the_first_page_with_nothing_new(): void
    {
        $this->fakeBitbucket();
        $sync = app(PortalBitbucketSync::class);
        $sync->syncLinked();
        Http::fake(fn (Request $request) => str_contains($request->url(), 'page=2')
            ? Http::response(['error' => ['message' => 'should not be read']], 500)
            : Http::response(['values' => [$this->commit(str_repeat('a', 40), '2020-01-05T17:30:00+00:00', 'x <x@x.x>')], 'next' => self::API.'/commits?pagelen=100&page=2']));

        $this->assertSame(['listed' => 0, 'counted' => 0], $sync->syncLinked()['ikaika-web/fixture-sync-2020']);
    }

    public function test_a_failure_is_kept_on_the_repository_without_the_token(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Token is invalid']], 401)]);

        $outcome = app(PortalBitbucketSync::class)->syncLinked()['ikaika-web/fixture-sync-2020'];

        $this->assertSame('Bitbucket HTTP 401: Token is invalid', $outcome);
        $error = (string) DB::connection('portal')->table('bitbucket_repositories')->where('id', $this->repositoryId)->value('last_sync_error');
        $this->assertSame($outcome, $error);
        $this->assertStringNotContainsString(self::TOKEN, $error);
    }

    // The token only ever goes to Bitbucket's API: a paging link elsewhere is refused, not followed.
    public function test_a_paging_link_off_the_api_is_never_followed(): void
    {
        Http::fake(['*' => Http::response(['values' => [], 'next' => 'https://evil.example.com/steal'])]);

        $outcome = app(PortalBitbucketSync::class)->syncLinked()['ikaika-web/fixture-sync-2020'];

        $this->assertSame('Bitbucket sent a link outside its API.', $outcome);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_without_a_token_nothing_is_sent(): void
    {
        config(['services.bitbucket.token' => null]);
        Http::fake();

        $this->assertSame('BITBUCKET_TOKEN is not set.', app(PortalBitbucketSync::class)->syncLinked()['ikaika-web/fixture-sync-2020']);
        Http::assertNothingSent();
    }

    public function test_the_command_reports_each_repository(): void
    {
        $this->fakeBitbucket();

        $this->artisan('portal:bitbucket-sync')
            ->expectsOutputToContain('ikaika-web/fixture-sync-2020: 4 new commits, 3 counted')
            ->assertExitCode(0);
    }

    // The report forms read one person's day across every project, each commit listed with its time.
    public function test_the_report_form_reads_its_own_day_by_project(): void
    {
        $this->fakeBitbucket();
        app(PortalBitbucketSync::class)->syncLinked();
        $developer = Employee::query()->findOrFail((int) $this->developer->id);

        $day = $this->getJson('/api/development/portal/reports/code-activity?day=2020-01-06', $this->headersFor($developer))->assertOk();

        $project = collect($day->json('data'))->firstWhere('projectId', (string) $this->projectId);
        $this->assertNotNull($project);
        $this->assertSame([1, 10, 5, 2, 0], [$project['commits'], $project['linesAdded'], $project['linesRemoved'], $project['filesChanged'], $project['uncounted']]);
        $this->assertSame(['hash' => 'aaaaaaa', 'time' => '01:30', 'repository' => 'fixture-sync-2020', 'message' => 'Work a', 'linesAdded' => 10, 'linesRemoved' => 5], $project['entries'][0]);
        $this->assertSame([], collect($this->getJson('/api/development/portal/reports/code-activity?day=2020-01-05', $this->headersFor($developer))->json('data'))
            ->where('projectId', (string) $this->projectId)->all());
        $this->getJson('/api/development/portal/reports/code-activity?day=06/01/2020', $this->headersFor($developer))->assertStatus(422);
    }

    // The dashboard's code tiles: whole-history totals for a project that links a repository, none for one that does not.
    public function test_the_board_carries_each_linked_projects_code(): void
    {
        $this->fakeBitbucket();
        app(PortalBitbucketSync::class)->syncLinked();
        $portal = DB::connection('portal');
        $portal->table('employees_projects')->insert(['project_id' => $this->projectId, 'employee_id' => (int) $this->developer->id, 'role_on_project' => 'member']);
        $portal->table('projects')->where('id', $this->projectId)->update(['msteams_project_link' => 'N/A', 'ms_loop_link' => 'https://loop.microsoft.com/p/1']);
        $developer = Employee::query()->findOrFail((int) $this->developer->id);

        $summary = app(PortalProjectCodeActivity::class)->summariesByProject([$this->projectId, $this->projectId + 100000]);
        $this->assertSame([$this->projectId], array_keys($summary));
        $this->assertSame(
            ['repositories' => 1, 'commits' => 3, 'linesAdded' => 30, 'linesRemoved' => 15, 'filesChanged' => 6, 'activeDays' => 3, 'contributors' => 2, 'lastCommitOn' => '2020-01-06', 'uncounted' => 0],
            $summary[$this->projectId],
        );

        $board = collect($this->getJson('/api/development/portal/projects', $this->headersFor($developer))->assertOk()->json('data'));
        $item = $board->firstWhere('id', (string) $this->projectId);
        $this->assertNotNull($item);
        $this->assertSame(3, $item['codeActivity']['commits']);
        // MIS: the top member changed the most lines (2 matched commits, 30 lines); everyone who opens the project sees it.
        $this->assertSame(['id' => (string) $this->developer->id, 'measure' => 'linesChanged', 'value' => 30, 'hours' => null, 'commits' => 2], array_diff_key($item['topMember'], ['name' => true, 'photoUrl' => true]));
        $this->assertArrayNotHasKey('topItems', $item['work']);
        // A placeholder link is no link; the linked repository joins the Details links.
        $this->assertSame([
            ['label' => 'MS Loop', 'url' => 'https://loop.microsoft.com/p/1'],
            ['label' => 'Bitbucket: fixture-sync-2020', 'url' => 'https://bitbucket.org/ikaika-web/fixture-sync-2020'],
        ], $item['links']);
        // The project's own code (its number) is untouched by the code tiles.
        $this->assertIsString($item['code']);
        $this->assertNull($board->first(fn (array $other): bool => $other['id'] !== (string) $this->projectId && $other['codeActivity'] !== null));
    }

    // After the counting rules change, --recount counts every stored commit again; merges stay uncounted.
    public function test_recount_counts_every_commit_again(): void
    {
        $this->fakeBitbucket();
        app(PortalBitbucketSync::class)->syncLinked();

        $this->artisan('portal:bitbucket-sync', ['--recount' => true])
            ->expectsOutputToContain('3 commits will be counted again.')
            ->expectsOutputToContain('ikaika-web/fixture-sync-2020: 0 new commits, 3 counted')
            ->assertExitCode(0);
    }

    // The Activity tab: each person's code beside their hours, and a report row shows the code of the day it covers.
    public function test_the_activity_tab_shows_code_by_person_and_report_day(): void
    {
        $this->fakeBitbucket();
        app(PortalBitbucketSync::class)->syncLinked();
        $portal = DB::connection('portal');
        $developerId = (int) $this->developer->id;
        $portal->table('employees_projects')->insert(['project_id' => $this->projectId, 'employee_id' => $developerId, 'role_on_project' => 'member']);
        $reportId = (int) $portal->table('user_reports')->insertGetId(['report_date' => '2020-01-06', 'hours_rendered' => 8]);
        $portal->table('projects_user_reports')->insert(['project_id' => $this->projectId, 'user_report_id' => $reportId]);
        $portal->table('employees_user_reports')->insert(['employee_id' => $developerId, 'user_report_id' => $reportId]);
        // Someone else's commit: a member who is not the lead sees that they committed, never how much.
        $other = (int) $portal->table('employees')->where('id', '<>', $developerId)->whereNotNull('email')->orderBy('id')->value('id');
        $portal->table('bitbucket_commits')->insert([
            'repository_id' => $this->repositoryId, 'hash' => str_repeat('f', 40), 'employee_id' => $other,
            'committed_at' => '2020-01-06 02:00:00', 'committed_on' => '2020-01-06', 'lines_added' => 99, 'counted_at' => now(),
        ]);
        $developer = Employee::query()->findOrFail($developerId);

        $page = $this->getJson('/api/development/portal/projects/'.$this->projectId.'/activity', $this->headersFor($developer))->assertOk();

        $people = collect($page->json('data.people'))->keyBy('id');
        $this->assertSame(['commits' => 2, 'linesAdded' => 20, 'linesRemoved' => 10, 'lastCommitOn' => '2020-01-06'], $people[(string) $developerId]['code']);
        $this->assertNull($people[(string) $other]['code']);
        $report = collect($page->json('data.reports'))->firstWhere('id', (string) $reportId);
        $this->assertSame(['commits' => 1, 'linesAdded' => 10, 'linesRemoved' => 5], $report['code'] ?? null);
    }

    public function test_generated_paths_never_count(): void
    {
        foreach (['package-lock.json', 'app/composer.lock', 'node_modules/a.js', 'graphify-out/graph.json', 'public/app.min.js', 'dist/x.js'] as $path) {
            $this->assertTrue(PortalBitbucketSync::isGenerated($path), $path);
        }
        foreach (['sql/portal/data.sql', 'migrations/outputs/users/export/User Reports.json', '.claude/skills/x/live.js', 'reference/lib/main.dart'] as $path) {
            $this->assertTrue(PortalBitbucketSync::isGenerated($path), $path);
        }
        // One file changing more than 5,000 lines is taken as generated, whatever its name.
        $this->assertTrue(PortalBitbucketSync::skips(['path' => 'src/huge.ts', 'added' => 4000, 'removed' => 1001]));
        $this->assertFalse(PortalBitbucketSync::skips(['path' => 'src/huge.ts', 'added' => 4000, 'removed' => 1000]));
        foreach (['src/app.ts', 'app/Support/Portal/PortalBitbucketSync.php', 'docs/build-notes.md', 'sql/portal/separate.sql'] as $path) {
            $this->assertFalse(PortalBitbucketSync::isGenerated($path), $path);
        }
    }
}
