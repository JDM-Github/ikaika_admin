<?php

namespace Tests\Feature;

use App\Support\Airtable\AirtableSyncMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5 (2026-10-22): Clients live in Client Information; Users & Projects holds a read-only synced
 * copy, so a local client carries both ids. The mapping command fills them by name.
 */
class PortalClientMappingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    public function test_the_mapping_command_fills_both_source_ids_by_name(): void
    {
        $id = (int) DB::connection('portal')->table('clients')->insertGetId([
            'name' => 'Mapping Client', 'client_id' => 'C-999',
        ]);
        config([
            'services.airtable.token' => 'test-token',
            'services.airtable.base' => 'appTESTBASE000000',
            'services.airtable.bases.clients' => 'appCLIENTS0000000',
        ]);
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            return match (true) {
                str_ends_with($path, '/Clients') && str_contains($path, 'appCLIENTS0000000') => Http::response(['records' => [
                    ['id' => 'recCLIENTINFO0001', 'fields' => ['Name' => 'Mapping Client']],
                ]]),
                str_ends_with($path, '/Clients') => Http::response(['records' => [
                    ['id' => 'recMAINCLIENT0001', 'fields' => ['Name' => 'Mapping Client']],
                ]]),
                default => Http::response(['records' => []]),
            };
        });

        $this->artisan('portal:map-clients --dry-run')->assertExitCode(0);
        $this->assertNull(DB::connection('portal')->table('clients')->where('id', $id)->value('client_info_airtable_record_id'));

        $this->artisan('portal:map-clients')->assertExitCode(0);

        $row = DB::connection('portal')->table('clients')->where('id', $id)->first();
        $this->assertSame('recCLIENTINFO0001', $row->client_info_airtable_record_id);
        $this->assertSame('recMAINCLIENT0001', $row->airtable_record_id);
    }

    public function test_the_client_resources_target_client_information(): void
    {
        $map = AirtableSyncMap::for('references.clients');
        $this->assertNotNull($map);
        $this->assertSame('clients', AirtableSyncMap::base($map));
        $this->assertSame('client_info_airtable_record_id', AirtableSyncMap::recordIdColumn($map));
        $this->assertSame('clients', AirtableSyncMap::base(AirtableSyncMap::for('clients')));
        $this->assertSame(['Name', 'Notes'], array_values($map['fields']));
    }
}
