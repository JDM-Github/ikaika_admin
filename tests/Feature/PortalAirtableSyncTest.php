<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Core\CoreLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalAirtableSyncTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core', 'portal'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.airtable.token' => 'test-token', 'services.airtable.base' => 'appTESTBASE000000']);
        // Earlier unsynced rows in the local ledger must not leak into these assertions.
        Action::query()->whereNull('synced_at')->update(['synced_at' => now()]);
    }

    public function test_a_dry_run_plans_the_create_and_writes_nothing_anywhere(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $actionId = $this->ledgerAdd($requestId);

        $report = app(PortalAirtableSync::class)->run(false);

        $item = collect($report['items'])->firstWhere('actionId', $actionId);
        $this->assertIsArray($item);
        $this->assertSame('create', $item['outcome']);
        $this->assertSame('Request', $item['table']);
        $this->assertSame('Pending', $item['fields']['Status']);
        $this->assertSame('Vacation Leave', $item['fields']['Category']);

        // Only the schema read went out; the ledger and the SQL row are untouched.
        Http::assertSentCount(1);
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
        $this->assertNull(DB::connection('portal')->table('requests')->where('id', $requestId)->value('airtable_record_id'));
    }

    public function test_a_sent_run_creates_writes_the_id_back_and_then_patches(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $addId = $this->ledgerAdd($requestId);

        app(PortalAirtableSync::class)->run(true);

        $this->assertSame('recNEW0000000001', DB::connection('portal')->table('requests')->where('id', $requestId)->value('airtable_record_id'));
        $this->assertNotNull(Action::query()->findOrFail($addId)->synced_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['typecast'] === false
            && $request['fields']['Date'] === '2026-10-05');

        // The next change to the same row patches the record it created instead of duplicating it.
        DB::connection('portal')->table('requests')->where('id', $requestId)->update(['status' => 'Approved']);
        $editId = $this->ledgerAdd($requestId, 'edit');

        app(PortalAirtableSync::class)->run(true);

        $this->assertNotNull(Action::query()->findOrFail($editId)->synced_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/recNEW0000000001')
            && $request['fields']['Status'] === 'Approved');
    }

    public function test_a_value_airtable_does_not_offer_is_blocked_and_never_sent(): void
    {
        $this->fakeAirtable();
        // Neither has a counterpart in the base, however it is spelled.
        $requestId = $this->leaveRow('Rescheduled', '03 Emergency Leave');
        $actionId = $this->ledgerAdd($requestId);

        $report = app(PortalAirtableSync::class)->run(true);

        $item = collect($report['items'])->firstWhere('actionId', $actionId);
        $this->assertIsArray($item);
        $this->assertSame('blocked', $item['outcome']);
        $this->assertCount(2, $item['problems']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // The portal's labels can be renumbered or recased without breaking the sync.
    public function test_a_numbered_or_recased_label_fits_the_option_airtable_offers(): void
    {
        $this->fakeAirtable();
        $numbered = $this->ledgerAdd($this->leaveRow('pending', '01 Vacation Leave'));
        $dotted = $this->ledgerAdd($this->leaveRow('Pending', '2. SICK  leave'));

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        $first = $items->firstWhere('actionId', $numbered);
        $this->assertIsArray($first);
        $this->assertSame('create', $first['outcome']);
        $this->assertSame('Vacation Leave', $first['fields']['Category']);
        $this->assertSame('Pending', $first['fields']['Status']);
        $second = $items->firstWhere('actionId', $dotted);
        $this->assertIsArray($second);
        $this->assertSame('Sick Leave', $second['fields']['Category']);
    }

    // A cancelled request was never really filed, so it must never become an Airtable record.
    public function test_a_request_cancelled_before_it_synced_is_never_sent(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Cancelled', 'Vacation Leave');
        $addId = $this->ledgerAdd($requestId);
        $editId = $this->ledgerAdd($requestId, 'edit');

        $report = app(PortalAirtableSync::class)->run(true);

        $this->assertSame(1, $report['counts']['synced']);
        $this->assertSame(1, $report['counts']['covered']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertNotNull(Action::query()->findOrFail($addId)->synced_at);
        $this->assertNotNull(Action::query()->findOrFail($editId)->synced_at);
    }

    public function test_a_request_cancelled_after_it_synced_is_deleted_from_airtable(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Cancelled', 'Vacation Leave');
        DB::connection('portal')->table('requests')->where('id', $requestId)->update(['airtable_record_id' => 'recOLD0000000001']);
        $this->ledgerAdd($requestId, 'edit');

        app(PortalAirtableSync::class)->run(true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/recOLD0000000001'));
        $this->assertNull(DB::connection('portal')->table('requests')->where('id', $requestId)->value('airtable_record_id'));
    }

    // Several actions on one row push its current state once, not once per action.
    public function test_several_actions_on_one_row_collapse_into_one_push(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $addId = $this->ledgerAdd($requestId);
        $editId = $this->ledgerAdd($requestId, 'edit');

        $dry = collect(app(PortalAirtableSync::class)->run(false)['items']);
        $this->assertSame('create', $dry->firstWhere('actionId', $addId)['outcome'] ?? null);
        $this->assertSame('covered', $dry->firstWhere('actionId', $editId)['outcome'] ?? null);

        app(PortalAirtableSync::class)->run(true);

        Http::assertSentCount(3); // two schema reads and exactly one create
        $this->assertNotNull(Action::query()->findOrFail($addId)->synced_at);
        $this->assertNotNull(Action::query()->findOrFail($editId)->synced_at);
    }

    public function test_an_unmapped_resource_is_left_for_later_and_a_failure_is_recorded(): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/*' => Http::response($this->schema()),
            'api.airtable.com/v0/*' => Http::response(['error' => ['type' => 'INVALID_REQUEST', 'message' => 'Nope']], 422),
        ]);
        $unmapped = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.user_reports', ['id' => 'x'], 'reports.submitted', 'x');
        $failing = $this->ledgerAdd($this->leaveRow('Pending', 'Sick Leave'));

        $report = app(PortalAirtableSync::class)->run(true);

        $this->assertSame(1, $report['counts']['skipped']);
        $this->assertSame(1, $report['counts']['failed']);
        $this->assertNull(Action::query()->findOrFail($unmapped)->synced_at);
        $failed = Action::query()->findOrFail($failing);
        $this->assertNull($failed->synced_at);
        $this->assertSame(1, (int) $failed->attempts);
        $this->assertStringContainsString('INVALID_REQUEST', (string) $failed->last_error);
    }

    public function test_the_command_is_a_dry_run_unless_told_to_send(): void
    {
        $this->fakeAirtable();
        $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));

        $this->artisan('portal:airtable-sync')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    private function fakeAirtable(): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/*' => Http::response($this->schema()),
            'api.airtable.com/v0/*' => Http::response(['id' => 'recNEW0000000001', 'fields' => []]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $select = static fn (array $names): array => ['choices' => array_map(static fn (string $name): array => ['name' => $name], $names)];

        return ['tables' => [[
            'name' => 'Request',
            'fields' => [
                ['name' => 'Date', 'type' => 'date'],
                ['name' => 'Name', 'type' => 'singleLineText'],
                ['name' => 'Reason', 'type' => 'singleLineText'],
                ['name' => 'Status', 'type' => 'singleSelect', 'options' => $select(['Approved', 'Pending', 'Rejected'])],
                ['name' => 'Category', 'type' => 'singleSelect', 'options' => $select(['Sick Leave', 'Vacation Leave'])],
                ['name' => 'Type', 'type' => 'singleLineText'],
                ['name' => 'Approver Remarks', 'type' => 'multilineText'],
            ],
        ]]];
    }

    private function leaveRow(string $status, string $category): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => '2026-10-05',
            'name' => 'Sync Tester',
            'reason' => 'Family trip',
            'status' => $status,
            'type' => 'Leave',
            'category' => $category,
        ]);
    }

    private function ledgerAdd(int $requestId, string $type = 'add'): int
    {
        $ledger = app(CoreLedger::class);

        return $type === 'add'
            ? $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-leave', 'portal.requests', ['id' => (string) $requestId], 'requests.leave', (string) $requestId, 1, null)
            : $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $requestId], 'requests.leave', (string) $requestId, null, 1, null);
    }
}
