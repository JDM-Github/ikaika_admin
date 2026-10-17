<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Every portal write is located (company policy, 2026-10-01): without X-Portal-Location a write is
 * refused with 428 location_required, before it does anything. Reads, signing in, Developer mode's
 * password and marking notices read go without one.
 */
class PortalLocationRequiredTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $member = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        // Signing in is exempt, so it works with no location at all.
        $this->flushHeaders();
        $this->token = (string) $this->postJson(self::BASE.'/auth/login', ['id_no' => $member->id_no])->assertOk()->json('token');
    }

    public function test_a_write_without_a_location_is_refused(): void
    {
        $this->withToken($this->token)->postJson(self::BASE.'/calendar/events', [])
            ->assertStatus(428)
            ->assertJsonPath('code', 'location_required')
            ->assertJsonPath('message', 'Saving needs your location. Turn location on for the portal and try again.');

        $this->withToken($this->token)->withHeader('X-Portal-Location', '   ')->postJson(self::BASE.'/calendar/events', [])
            ->assertStatus(428);
    }

    // With a location the write reaches its own checks: an empty event is a validation error, not 428.
    public function test_a_located_write_goes_through_to_its_endpoint(): void
    {
        $status = $this->withToken($this->token)->withHeader('X-Portal-Location', '14.5995, 120.9842')
            ->postJson(self::BASE.'/calendar/events', [])->status();

        $this->assertNotSame(428, $status);
    }

    public function test_reads_and_the_exempt_writes_need_no_location(): void
    {
        $this->withToken($this->token)->getJson(self::BASE.'/home')->assertOk();
        $this->withToken($this->token)->postJson(self::BASE.'/user/notifications/read-all')->assertSuccessful();
        $this->assertNotSame(428, $this->withToken($this->token)->patchJson(self::BASE.'/developer/mode', ['on' => true])->status());

        // The remark tidy-up writes nothing, so it never needs a location either -- and without one it
        // must reach its own endpoint (a validation refusal, not 428).
        $this->assertNotSame(428, $this->withToken($this->token)
            ->postJson(self::BASE.'/reports/remarks/format', ['remark' => '', 'day' => '2026-10-17'])->status());
    }
}
