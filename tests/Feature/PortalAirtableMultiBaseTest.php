<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Support\Airtable\AirtableClient;
use App\Support\Airtable\AirtableSyncMap;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Core\CoreLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 1 of the multi-base work (2026-10-22): a named base, the transaction-tracker product, and the
 * per-resource metadata. The existing single-base suite stays the regression net.
 */
class PortalAirtableMultiBaseTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core', 'portal'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.airtable.token' => 'test-token',
            'services.airtable.base' => 'appTESTBASE000000',
            'services.airtable.bases.calendar' => 'appCALENDAR000000',
            'services.airtable.bases.tracker' => 'appTRACKER0000000',
        ]);
        Action::query()->whereNull('synced_at')->update(['synced_at' => now()]);
    }

    public function test_a_named_base_is_used_for_its_own_calls(): void
    {
        Http::fake(['api.airtable.com/v0/meta/*' => Http::response(['tables' => []])]);

        app(AirtableClient::class)->tables('calendar');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/meta/bases/appCALENDAR000000/tables'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'appTESTBASE000000'));
    }

    public function test_the_main_base_stays_the_default(): void
    {
        Http::fake(['api.airtable.com/v0/meta/*' => Http::response(['tables' => []])]);

        app(AirtableClient::class)->tables();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/meta/bases/appTESTBASE000000/tables'));
    }

    public function test_a_transaction_tracker_action_is_planned_by_the_same_sync(): void
    {
        $actionId = app(CoreLedger::class)->recordAdd(
            CoreLedger::PRODUCT_TRANSACTION_TRACKER,
            'tracker:1',
            'tracker.transactions',
            ['id' => '1'],
            'tracker.smoke',
            '1',
            null,
            null,
        );

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('transaction-tracker', Action::query()->findOrFail($actionId)->product);
        $this->assertSame('skipped', $item['outcome']);
        $this->assertStringContainsString('Not mapped', $item['problems'][0]);
    }

    public function test_a_send_with_the_prod_profile_is_refused_outside_production(): void
    {
        config(['services.airtable.env' => 'prod']);

        $this->artisan('portal:airtable-sync --send')
            ->expectsOutputToContain('Refused: AIRTABLE_ENV=prod')
            ->assertExitCode(1);
    }

    public function test_the_map_defaults_and_the_second_record_id_column(): void
    {
        $this->assertSame('main', AirtableSyncMap::base([]));
        $this->assertSame('portal', AirtableSyncMap::product([]));
        $this->assertSame('portal', AirtableSyncMap::connection([]));
        $this->assertSame('airtable_record_id', AirtableSyncMap::recordIdColumn([]));
        $this->assertSame('calendar_airtable_record_id', AirtableSyncMap::recordIdColumn(['record_id_column' => 'calendar_airtable_record_id']));
    }
}
