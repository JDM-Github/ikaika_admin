<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * No save needs a location since 2026-10-19 (supervisor): with none sent, a write reaches its own checks,
 * and its log keeps whatever place the network gives.
 */
class PortalWriteLocationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal';

    public function test_a_write_without_a_location_reaches_its_endpoint(): void
    {
        Cache::flush();
        $member = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $this->flushHeaders();
        $token = (string) $this->postJson(self::BASE.'/auth/login', ['id_no' => $member->id_no])->assertOk()->json('token');

        // An empty event is the endpoint's own validation error, never the old 428 location_required.
        $this->assertNotSame(428, $this->withToken($token)->postJson(self::BASE.'/calendar/events', [])->status());
    }
}
