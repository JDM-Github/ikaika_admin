<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalHomeLocations;
use App\Support\Portal\PortalLocationExemptions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * location_exemptions (2026-10-19, user): every record an exempt person's act writes says Unknown, not even
 * the network's place (the IP stays), they are never asked for a home pin, and nobody places them from
 * their address. Nobody needs a location to write any more.
 */
class PortalLocationExemptionsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal';

    private Employee $exempt;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        [$this->exempt, $this->other] = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit(2)
            ->get()
            ->all();
        DB::connection('portal')->table('location_exemptions')->whereIn('employee_id', [$this->exempt->getKey(), $this->other->getKey()])->delete();
        DB::connection('portal')->table('location_exemptions')->insert(['employee_id' => $this->exempt->getKey(), 'reason' => 'test']);
    }

    // No write needs a location any more (2026-10-19, supervisor): with none sent, the write reaches its own checks.
    public function test_anyone_writes_without_a_location(): void
    {
        $this->flushHeaders();

        $this->assertNotSame(428, $this->withToken($this->token($this->exempt))->postJson(self::BASE.'/calendar/events', [])->status());
        $this->assertNotSame(428, $this->withToken($this->token($this->other))->postJson(self::BASE.'/calendar/events', [])->status());
    }

    public function test_the_session_says_who_is_exempt(): void
    {
        $this->flushHeaders();

        $this->assertTrue($this->postJson(self::BASE.'/auth/login', ['id_no' => $this->exempt->id_no])->assertOk()->json('employee.location_exempt'));
        $this->assertFalse($this->postJson(self::BASE.'/auth/login', ['id_no' => $this->other->id_no])->assertOk()->json('employee.location_exempt'));
    }

    public function test_an_exempt_act_is_recorded_as_unknown_even_when_a_place_is_sent(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_X_PORTAL_LOCATION' => 'Calamba, Laguna',
            'HTTP_X_PORTAL_LOCATION_LAT' => '14.2',
            'HTTP_X_PORTAL_LOCATION_LNG' => '121.1',
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
        $request->attributes->set('portalEmployee', $this->exempt);

        $id = app(PortalAudit::class)->record($this->exempt, 'POST', 'test.location', 'Exempt act', null, $request);

        $row = DB::connection('portal')->table('logs')->where('id', $id)->first();
        $this->assertSame(PortalLocationExemptions::UNKNOWN, $row->location_label);
        $this->assertNull($row->location_lat);
        $this->assertNull($row->location_lng);
        $this->assertSame('203.0.113.9', $row->ip_address);
    }

    public function test_signing_in_records_no_place_for_an_exempt_person(): void
    {
        $this->flushHeaders();
        $this->withHeader('X-Portal-Location', 'Calamba, Laguna')->postJson(self::BASE.'/auth/login', ['id_no' => $this->exempt->id_no])->assertOk();

        $row = DB::connection('portal')->table('logs')->where('employee_id', $this->exempt->getKey())->orderByDesc('id')->first();
        $this->assertSame(PortalLocationExemptions::UNKNOWN, $row->location_label);
    }

    public function test_an_exempt_person_is_never_asked_for_or_given_a_home(): void
    {
        $homes = app(PortalHomeLocations::class);

        $this->assertFalse($homes->mine($this->exempt)['required']);
        $this->assertFalse($homes->followAddress((int) $this->exempt->getKey()));
        $this->expectException(HttpException::class);
        $homes->setOwn((int) $this->exempt->getKey(), 14.2, 121.1, 'map');
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson(self::BASE.'/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }
}
