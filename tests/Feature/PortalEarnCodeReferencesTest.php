<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Airtable\AirtableSyncMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / References' Earn Codes section (2026-10-22, user): one Description and two multipliers,
 * written to the Calendar base, refusing removal while reports/requests use it.
 */
class PortalEarnCodeReferencesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/references';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function employee(string $where): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    private function adminToken(): string
    {
        return $this->token($this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'"));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function add(string $token, array $body): TestResponse
    {
        return $this->withToken($token)->postJson(self::BASE.'/earn-codes', $body);
    }

    public function test_the_page_serves_earn_codes_with_their_usage(): void
    {
        $payload = $this->withToken($this->adminToken())->getJson(self::BASE)->assertOk();
        $codes = $payload->json('data.earnCodes');

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
        $this->assertArrayHasKey('description', $codes[0]);
        $this->assertArrayHasKey('didNotWork', $codes[0]);
        $this->assertArrayHasKey('didWork', $codes[0]);
        $this->assertArrayHasKey('usages', $codes[0]);
    }

    public function test_an_earn_code_is_added_edited_and_removed(): void
    {
        $token = $this->adminToken();

        $added = $this->add($token, ['description' => '33 Test Earn', 'didNotWork' => 0, 'didWork' => 1.5])->assertOk();
        $row = collect($added->json('data.earnCodes'))->firstWhere('description', '33 Test Earn');
        $this->assertIsArray($row);
        $this->assertSame(0.0, (float) $row['didNotWork']);
        $this->assertSame(1.5, (float) $row['didWork']);
        $this->assertTrue(DB::connection('core')->table('actions')->where('resource', 'references.earn_codes')
            ->where('record_id', (string) $row['id'])->where('action_type', 'add')->exists());

        $this->withToken($token)->patchJson(self::BASE.'/earn-codes/'.$row['id'], [
            'description' => '33 Test Earn v2', 'didNotWork' => 0, 'didWork' => 1.75,
        ])->assertOk()->assertJsonPath('data.earnCodes', fn (array $codes): bool => collect($codes)->contains(
            fn (array $code): bool => $code['id'] === $row['id'] && $code['description'] === '33 Test Earn v2' && (float) $code['didWork'] === 1.75,
        ));

        $edit = DB::connection('core')->table('actions')->where('resource', 'references.earn_codes')
            ->where('record_id', (string) $row['id'])->where('action_type', 'edit')->first();
        $this->assertNotNull($edit);
        $fields = array_keys((array) (json_decode((string) $edit->parameters, true)['parameters']['fields'] ?? []));
        sort($fields);
        $this->assertSame(['description', 'did_work'], $fields);

        $this->withToken($token)->deleteJson(self::BASE.'/earn-codes/'.$row['id'])->assertOk()
            ->assertJsonPath('data.earnCodes', fn (array $codes): bool => collect($codes)->doesntContain(
                fn (array $code): bool => $code['id'] === $row['id'],
            ));
        $this->assertFalse(DB::connection('portal')->table('earn_codes')->where('id', $row['id'])->exists());
        $this->assertTrue(DB::connection('core')->table('actions')->where('resource', 'references.earn_codes')
            ->where('record_id', (string) $row['id'])->where('action_type', 'delete')->exists());
    }

    public function test_removing_an_earn_code_in_use_is_refused(): void
    {
        $token = $this->adminToken();
        $codeId = (int) DB::connection('portal')->table('user_reports_earn_codes')->value('earn_code_id');

        $this->withToken($token)->deleteJson(self::BASE.'/earn-codes/'.$codeId)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'so it stays'));

        $this->assertTrue(DB::connection('portal')->table('earn_codes')->where('id', $codeId)->exists());
    }

    public function test_the_mapping_command_fills_both_source_ids_by_description(): void
    {
        $id = (int) DB::connection('portal')->table('earn_codes')->insertGetId([
            'description' => '34 Mapping Test', 'did_not_work' => 0, 'did_work' => 1,
        ]);
        config([
            'services.airtable.token' => 'test-token',
            'services.airtable.base' => 'appTESTBASE000000',
            'services.airtable.bases.calendar' => 'appCALENDAR000000',
        ]);
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            return match (true) {
                str_ends_with($path, '/Earn Codes') && str_contains($path, 'appCALENDAR000000') => Http::response(['records' => [
                    ['id' => 'recCALENDAR000001', 'fields' => ['Description' => '34 Mapping Test']],
                ]]),
                str_ends_with($path, '/Earn Codes') => Http::response(['records' => [
                    ['id' => 'recMAIN0000000001', 'fields' => ['Description' => '34 Mapping Test']],
                ]]),
                default => Http::response(['records' => []]),
            };
        });

        $this->artisan('portal:map-earn-codes --dry-run')->assertExitCode(0);
        $this->assertNull(DB::connection('portal')->table('earn_codes')->where('id', $id)->value('calendar_airtable_record_id'));

        $this->artisan('portal:map-earn-codes')->assertExitCode(0);

        $row = DB::connection('portal')->table('earn_codes')->where('id', $id)->first();
        $this->assertSame('recCALENDAR000001', $row->calendar_airtable_record_id);
        $this->assertSame('recMAIN0000000001', $row->airtable_record_id);
    }

    public function test_the_earn_code_resources_target_calendar(): void
    {
        $map = AirtableSyncMap::for('references.earn_codes');
        $this->assertNotNull($map);
        $this->assertSame('calendar', AirtableSyncMap::base($map));
        $this->assertSame('calendar_airtable_record_id', AirtableSyncMap::recordIdColumn($map));
        $this->assertSame('calendar', AirtableSyncMap::base(AirtableSyncMap::for('earn_codes')));
    }
}
