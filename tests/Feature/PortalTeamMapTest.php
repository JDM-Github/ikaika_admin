<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalHomeLocations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalTeamMapTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/map';

    private const BASE_HOME = '/api/development/portal/user/home';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        DB::connection('portal')->table('employee_home_locations')->delete();
    }

    private function employee(string $role): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw('LOWER(COALESCE(role, \'\')) = ?', [$role])
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    // The reported ask: each person at their address on record, trying shorter forms, then their region.
    public function test_each_person_is_placed_from_their_address_then_their_region(): void
    {
        $member = $this->employee('user');
        $member->forceFill(['address' => 'Blk 12 Lot 3 Cutud Homes, Angeles City, Pampanga, (2009) Philippines', 'location' => 'Pampanga'])->save();
        DB::connection('portal')->table('employees')->where('id', '!=', $member->getKey())->update(['address' => null, 'location' => null]);
        $asked = [];
        Http::fake(function (ClientRequest $request) use (&$asked) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $asked[] = $query['q'];

            // Only the town and province, or the region, are found, as house details throw Nominatim.
            return Http::response(match ($query['q']) {
                'Angeles City, Pampanga, Philippines' => [['lat' => '15.1450', 'lon' => '120.5887']],
                'Pampanga, Philippines' => [['lat' => '15.05', 'lon' => '120.64']],
                default => [],
            });
        });

        $homes = new PortalHomeLocations(false);
        $this->assertSame(['placed' => 1, 'failed' => 0, 'unchanged' => 0], $homes->refresh());
        $this->assertSame([
            'Blk 12 Lot 3 Cutud Homes, Angeles City, Pampanga, (2009) Philippines',
            'Blk 12 Lot 3 Cutud Homes, Angeles City, Pampanga, Philippines',
            'Angeles City, Pampanga, Philippines',
        ], $asked);
        // An unchanged address is not asked again.
        $this->assertSame(['placed' => 0, 'failed' => 0, 'unchanged' => 1], $homes->refresh());

        $response = $this->withToken($this->token($this->employee('admin')))->getJson(self::BASE)->assertOk();
        $person = collect($response->json('data.people'))->firstWhere('id', (string) $member->getKey());
        $this->assertSame(['Angeles City, Pampanga', 'address', 15.145, 120.5887], [$person['place'], $person['source'], $person['latitude'], $person['longitude']]);
        $this->assertGreaterThan(0, $response->json('data.unlocated'));

        // Nothing for the address at all: the region places them.
        $member->forceFill(['address' => 'Somewhere Unknown, Nowhere'])->save();
        $this->assertSame(1, $homes->refresh()['placed']);
        $person = collect($this->withToken($this->token($this->employee('admin')))->getJson(self::BASE)->json('data.people'))->firstWhere('id', (string) $member->getKey());
        $this->assertSame(['Pampanga', 'region'], [$person['place'], $person['source']]);

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
    }

    // The reported ask: the page is for the team, never for an executive's own record or place.
    public function test_executives_are_left_out_of_the_map_entirely(): void
    {
        $executive = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->orderBy('id')
            ->first();

        if (! $executive instanceof Employee) {
            $this->markTestSkipped('No active executive exists locally to check against.');
        }

        DB::connection('portal')->table('employee_home_locations')->insert([
            'employee_id' => $executive->getKey(), 'query' => 'Makati', 'source' => 'map', 'latitude' => 14.5547, 'longitude' => 121.0244,
        ]);

        $response = $this->withToken($this->token($this->employee('admin')))->getJson(self::BASE)->assertOk();
        $ids = collect($response->json('data.people'))->pluck('id')->all();

        $this->assertNotContains((string) $executive->getKey(), $ids);
        // Placed and unlocated together are the non-executive active people: an executive is neither a pin nor a gap.
        $actives = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw("LOWER(COALESCE(role, '')) != 'executive'")
            ->count();
        $this->assertSame($actives, count($ids) + $response->json('data.unlocated'));
    }

    // The reported ask: a member sets their own home after signing in; the address never moves it again.
    public function test_a_member_sets_their_own_home_and_the_address_leaves_it(): void
    {
        $member = $this->employee('user');
        $member->forceFill(['address' => 'Angeles City, Pampanga', 'location' => 'Pampanga'])->save();
        DB::connection('portal')->table('employee_home_locations')->insert([
            'employee_id' => $member->getKey(), 'query' => 'Angeles City, Pampanga', 'source' => 'address', 'latitude' => 15.145, 'longitude' => 120.5887,
        ]);
        $token = $this->token($member);
        $home = self::BASE_HOME;

        $this->withToken($token)->getJson($home)->assertOk()
            ->assertJsonPath('data.home', null)
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.suggested.latitude', 15.145);

        $this->withToken($token)->putJson($home, ['latitude' => 15.15, 'longitude' => 120.6, 'method' => 'teleport'])->assertStatus(422);
        $this->withToken($token)->putJson($home, ['latitude' => 95, 'longitude' => 120.6, 'method' => 'gps'])->assertStatus(422);
        $this->withToken($token)->withHeader('X-Portal-Location', 'Angeles City')->putJson($home, ['latitude' => 15.1501, 'longitude' => 120.6002, 'method' => 'map'])->assertOk()
            ->assertJsonPath('data.required', false)
            ->assertJsonPath('data.home.method', 'map');

        // A changed address does not move a home the person set.
        $member->forceFill(['address' => 'Somewhere Else, Laguna'])->save();
        Http::fake();
        (new PortalHomeLocations(false))->refresh();
        $this->assertSame('15.1501000', (string) DB::connection('portal')->table('employee_home_locations')->where('employee_id', $member->getKey())->value('latitude'));

        // An executive is never asked.
        $executive = Employee::query()->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")->whereNotNull('id_no')->first();
        if ($executive instanceof Employee) {
            $this->withToken($this->token($executive))->getJson($home)->assertOk()->assertJsonPath('data.required', false);
        }
    }

    // The reported ask (2026-10-14, user): the map follows its reach, narrowed to a department here.
    public function test_the_map_follows_a_reach_narrowed_to_a_department(): void
    {
        $admin = $this->employee('admin');
        $stranger = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw("LOWER(COALESCE(department, '')) != ?", [strtolower(trim((string) $admin->department))])
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        foreach ([$admin, $stranger] as $person) {
            DB::connection('portal')->table('employee_home_locations')->insert([
                'employee_id' => $person->getKey(), 'query' => 'Somewhere', 'source' => 'map', 'latitude' => 14.5, 'longitude' => 121.0,
            ]);
        }
        $token = $this->token($admin);

        $all = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data.people'))->pluck('id');
        $this->assertContains((string) $stranger->getKey(), $all);

        DB::connection('portal')->table('access_self_reach')->updateOrInsert(
            ['employee_id' => $admin->getKey(), 'permission_key' => 'manage.map'],
            ['reach' => 'department'],
        );
        $scoped = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data.people'))->pluck('id');
        $this->assertContains((string) $admin->getKey(), $scoped);
        $this->assertNotContains((string) $stranger->getKey(), $scoped);
    }
}
