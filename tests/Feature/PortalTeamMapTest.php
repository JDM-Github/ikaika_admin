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
}
