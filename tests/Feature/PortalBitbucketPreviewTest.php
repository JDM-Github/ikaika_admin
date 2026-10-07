<?php

namespace Tests\Feature;

use App\Support\Portal\PortalBitbucketPreview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The Bitbucket dry run against faked responses: one day's commits of a pasted repository, each file and
 * a summary per author, read and counted as the sync would, with nothing saved.
 */
class PortalBitbucketPreviewTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const API = 'https://api.bitbucket.org/2.0/repositories/ikaika-web/preview-fixture';

    private object $developer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bitbucket.token' => 'fixture-token']);
        $this->developer = DB::connection('portal')->table('employees')->whereNotNull('email')->where('email', 'like', '%@%')
            ->orderBy('id')->first(['id', 'email', 'first_name', 'last_name']);
    }

    /**
     * @param  list<string>  $parents
     * @return array<string, mixed>
     */
    private function commit(string $hash, string $date, string $email, array $parents = ['p']): array
    {
        return [
            'hash' => $hash,
            'date' => $date,
            'author' => ['raw' => 'Dev <'.$email.'>'],
            'message' => "Fix the list\n\nLonger body",
            'parents' => array_map(static fn (string $parent): array => ['hash' => $parent], $parents),
        ];
    }

    private function fakeBitbucket(): void
    {
        $mine = (string) $this->developer->email;
        Http::fake(function (Request $request) use ($mine) {
            $url = $request->url();
            if (str_contains($url, '/commits') && str_contains($url, 'page=2')) {
                // Wholly older than the day: the preview stops here and never asks for page 3.
                return Http::response(['values' => [$this->commit(str_repeat('e', 40), '2020-01-01T09:00:00+08:00', $mine)], 'next' => self::API.'/commits?page=3']);
            }
            if (str_contains($url, '/commits')) {
                return Http::response([
                    'values' => [
                        $this->commit(str_repeat('a', 40), '2020-01-07T02:00:00+08:00', $mine),
                        $this->commit(str_repeat('b', 40), '2020-01-06T15:00:00+08:00', $mine),
                        $this->commit(str_repeat('c', 40), '2020-01-06T14:00:00+08:00', $mine, ['p', 'q']),
                        $this->commit(str_repeat('d', 40), '2020-01-06T10:00:00+08:00', 'stranger@example.org'),
                    ],
                    'next' => self::API.'/commits?pagelen=100&page=2',
                ]);
            }
            if (str_contains($url, '/diffstat/')) {
                return Http::response(['values' => [
                    ['lines_added' => 12, 'lines_removed' => 3, 'new' => ['path' => 'src/list.ts']],
                    ['lines_added' => 900, 'lines_removed' => 800, 'new' => ['path' => 'package-lock.json']],
                ]]);
            }

            return Http::response([], 404);
        });
    }

    public function test_one_days_commits_show_each_file_and_a_summary_per_author(): void
    {
        $this->fakeBitbucket();

        $result = app(PortalBitbucketPreview::class)->day('https://bitbucket.org/IKAIKA-web/preview-fixture/src/main/', '2020-01-06');

        $this->assertSame('ikaika-web/preview-fixture', $result['repository']);
        $this->assertSame([str_repeat('b', 40), str_repeat('c', 40), str_repeat('d', 40)], array_column($result['commits'], 'hash'));
        $first = $result['commits'][0];
        $this->assertSame(['15:00', 'Fix the list', ['added' => 12, 'removed' => 3, 'files' => 1]], [$first['time'], $first['message'], $first['lines']]);
        $this->assertTrue($first['files'][1]['generated']);
        $this->assertSame([], $result['commits'][1]['files']);

        $byEmployee = collect($result['authors'])->keyBy('employee');
        $this->assertSame(1, $byEmployee['Unknown author']['commits']);
        $developer = $byEmployee[trim($this->developer->first_name.' '.$this->developer->last_name).' (#'.$this->developer->id.')'];
        $this->assertSame([1, 1, 12, 3, 1], [$developer['commits'], $developer['merges'], $developer['added'], $developer['removed'], $developer['files']]);

        $this->assertTrue($result['complete']);
        $this->assertSame(2, $result['pagesRead']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'page=3'));
    }

    public function test_the_author_filter_keeps_only_that_address(): void
    {
        $this->fakeBitbucket();

        $result = app(PortalBitbucketPreview::class)->day('ikaika-web/preview-fixture', '2020-01-06', 'STRANGER@example.org');

        $this->assertSame([str_repeat('d', 40)], array_column($result['commits'], 'hash'));
    }

    public function test_a_bad_repository_or_day_is_refused_before_any_call(): void
    {
        Http::fake();
        $preview = app(PortalBitbucketPreview::class);

        $this->assertThrows(fn () => $preview->day('https://github.com/x/y', '2020-01-06'), RuntimeException::class, 'That is not a bitbucket.org link or workspace/repository.');
        $this->assertThrows(fn () => $preview->day('ikaika-web/preview-fixture', '06/01/2020'), RuntimeException::class, 'Give the day as YYYY-MM-DD.');
        Http::assertNothingSent();
    }

    public function test_the_command_prints_the_day_and_saves_nothing(): void
    {
        $this->fakeBitbucket();
        $before = DB::connection('portal')->table('bitbucket_commits')->count();

        $this->artisan('portal:bitbucket-preview', ['repository' => 'ikaika-web/preview-fixture', '--day' => '2020-01-06'])
            ->expectsOutputToContain('ikaika-web/preview-fixture on 2020-01-06 (Asia/Manila time)')
            ->expectsOutputToContain('package-lock.json  (generated, not counted)')
            ->expectsOutputToContain('merge commit: never counted')
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->assertExitCode(0);

        $this->assertSame($before, DB::connection('portal')->table('bitbucket_commits')->count());
        $this->assertSame(0, DB::connection('portal')->table('bitbucket_repositories')->where('repo_slug', 'preview-fixture')->count());
    }

    public function test_the_command_says_why_it_stops(): void
    {
        Http::fake();

        $this->artisan('portal:bitbucket-preview', ['repository' => 'not a repository'])
            ->expectsOutputToContain('That is not a bitbucket.org link or workspace/repository.')
            ->assertExitCode(1);
    }
}
