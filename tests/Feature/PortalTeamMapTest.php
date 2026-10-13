<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    // The reported ask: an administrator sees where each person last saved something; a member does not.
    public function test_an_administrator_sees_each_persons_last_located_action(): void
    {
        $member = $this->employee('user');
        DB::connection('portal')->table('logs')->insert([
            ['employee_id' => $member->getKey(), 'action' => 'PATCH', 'resource' => 'test', 'location_lat' => 14.5, 'location_lng' => 121.0, 'location_label' => 'Old place', 'created_at' => now()->subDay()],
            ['employee_id' => $member->getKey(), 'action' => 'PATCH', 'resource' => 'test', 'location_lat' => 10.3157, 'location_lng' => 123.8854, 'location_label' => 'Cebu City', 'created_at' => now()],
            ['employee_id' => $member->getKey(), 'action' => 'GET', 'resource' => 'test', 'location_lat' => null, 'location_lng' => null, 'location_label' => null, 'created_at' => now()],
        ]);

        $response = $this->withToken($this->token($this->employee('admin')))->getJson(self::BASE)->assertOk();
        $person = collect($response->json('data.people'))->firstWhere('id', (string) $member->getKey());
        $this->assertNotNull($person);
        $this->assertSame(['Cebu City', 10.3157, 123.8854], [$person['place'], $person['latitude'], $person['longitude']]);
        $this->assertIsInt($response->json('data.unlocated'));

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
    }
}
