<?php

namespace Tests\Feature;

use App\Support\Portal\PortalJiraPeople;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Who in MIS is who in Jira (2026-10-22, user): found by work address, kept a day, and only MIS is ever looked up.
class PortalJiraPeopleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const SITE = 'https://ikaika-test.atlassian.net';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.jira' => [
            ...config('services.jira'),
            'base_url' => self::SITE,
            'email' => 'svc@ikaika.test',
            'token' => 'secret-token',
            'project' => 'IKP',
        ]]);
    }

    public function test_a_member_is_the_one_active_person_jira_finds_by_their_work_address(): void
    {
        [$found, $nobody, $twice] = $this->misMembers(['found@ikaika.test', 'nobody@ikaika.test', 'twice@ikaika.test']);
        $outsider = (int) DB::connection('portal')->table('employees')
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = 'engineering'")->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")->value('id');
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/user/search*' => fn (Request $request) => Http::response(match ($this->searchedFor($request)) {
            'found@ikaika.test' => [
                ['accountId' => '712020:found', 'accountType' => 'atlassian', 'active' => true],
                // Never an app, a deactivated person, or someone whose shown address is another.
                ['accountId' => 'app-bot', 'accountType' => 'app', 'active' => true],
                ['accountId' => '712020:left', 'accountType' => 'atlassian', 'active' => false],
                ['accountId' => '712020:other', 'accountType' => 'atlassian', 'active' => true, 'emailAddress' => 'someone.else@ikaika.test'],
            ],
            'twice@ikaika.test' => [
                ['accountId' => '712020:one', 'accountType' => 'atlassian', 'active' => true],
                ['accountId' => '712020:two', 'accountType' => 'atlassian', 'active' => true, 'emailAddress' => 'TWICE@ikaika.test'],
            ],
            default => [],
        })]);
        $people = app(PortalJiraPeople::class);

        $this->assertSame('712020:found', $people->accountOf($found));
        $this->assertNull($people->accountOf($nobody));
        // Two people for one address is no answer.
        $this->assertNull($people->accountOf($twice));
        $this->assertSame($found, $people->memberOf('712020:found'));
        $this->assertNull($people->memberOf('712020:other'));
        // Someone outside MIS, and nobody, are never looked up.
        $asked = Http::recorded()->count();
        $this->assertNull($people->accountOf($outsider));
        $this->assertNull($people->accountOf(null));
        $this->assertCount($asked, Http::recorded());
        // Only the address goes to Jira, as the search.
        Http::assertSent(fn (Request $request): bool => $this->searchedFor($request) === 'found@ikaika.test');
    }

    // Kept a day, "no account" too; a changed address is asked about afresh.
    public function test_an_answer_is_kept_a_day_and_a_new_address_is_asked_again(): void
    {
        [$member] = $this->misMembers(['nobody@ikaika.test']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/user/search*' => Http::response([])]);

        $this->assertNull(app(PortalJiraPeople::class)->accountOf($member));
        $this->assertNull(app(PortalJiraPeople::class)->accountOf($member));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $this->searchedFor($request) === 'nobody@ikaika.test'));

        DB::connection('portal')->table('employees')->where('id', $member)->update(['email' => 'renamed@ikaika.test']);
        app(PortalJiraPeople::class)->accountOf($member);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $this->searchedFor($request) === 'renamed@ikaika.test'));

        $this->travel(25)->hours();
        app(PortalJiraPeople::class)->accountOf($member);
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => $this->searchedFor($request) === 'renamed@ikaika.test'));
    }

    private function searchedFor(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return strtolower((string) ($query['query'] ?? ''));
    }

    /**
     * Active MIS members, each given one of these work addresses for the test.
     *
     * @param  list<string>  $addresses
     * @return list<int>
     */
    private function misMembers(array $addresses): array
    {
        $ids = DB::connection('portal')->table('employees')
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = 'mis'")->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->orderBy('id')->limit(count($addresses))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(count($addresses), $ids, count($addresses).' active MIS members are needed.');
        foreach ($ids as $index => $id) {
            DB::connection('portal')->table('employees')->where('id', $id)->update(['email' => $addresses[$index]]);
        }

        return $ids;
    }
}
