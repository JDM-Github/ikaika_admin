<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Support\Airtable\AirtableSyncMap;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    // Links carry the linked rows' Airtable ids; a DECIMAL string reaches a number field as a number.
    public function test_an_overtime_links_its_projects_by_their_airtable_ids(): void
    {
        $this->fakeAirtable();
        $portal = DB::connection('portal');
        $project = $portal->table('projects')->whereNotNull('airtable_record_id')->orderBy('id')->first();
        $this->assertNotNull($project);
        $requestId = (int) $portal->table('requests')->insertGetId([
            'request_date' => '2026-10-05', 'name' => 'Sync Tester', 'no_of_hours' => '2.50',
            'reason' => 'Late model push', 'status' => 'Pending', 'type' => 'Overtime',
        ]);
        $portal->table('projects_requests')->insert(['project_id' => $project->id, 'request_id' => $requestId]);
        $actionId = $this->ledgerAdd($requestId, 'edit', 'requests.overtime');

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('create', $item['outcome']);
        $this->assertSame([$project->airtable_record_id], $item['fields']['Project']);
        $this->assertSame(2.5, $item['fields']['No. of Hours']);
    }

    public function test_a_link_airtable_has_never_seen_blocks_the_row(): void
    {
        $this->fakeAirtable();
        $portal = DB::connection('portal');
        $projectId = (int) $portal->table('projects')->insertGetId(['project_name' => 'Portal-only project']);
        $requestId = (int) $portal->table('requests')->insertGetId([
            'request_date' => '2026-10-05', 'name' => 'Sync Tester', 'status' => 'Pending', 'type' => 'Overtime',
        ]);
        $portal->table('projects_requests')->insert(['project_id' => $projectId, 'request_id' => $requestId]);
        $actionId = $this->ledgerAdd($requestId, 'edit', 'requests.overtime');

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('blocked', $item['outcome']);
        $this->assertStringContainsString('projects#'.$projectId, $item['problems'][0]);
    }

    // The portal says Approved where this base says Completed; the alias bridges the two words.
    public function test_a_reimbursement_sends_its_receipt_link_and_translated_status(): void
    {
        $this->fakeAirtable();
        $portal = DB::connection('portal');
        $employee = $portal->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->first();
        $this->assertNotNull($employee);
        $itemId = (int) $portal->table('reimbursements')->insertGetId([
            'reimb_date' => '2026-10-05', 'item' => 'Water Refill', 'cost' => '40.00', 'qty' => 4,
            'purpose' => 'Office water', 'team' => '02 Cebu Office', 'status' => 'Approved',
        ]);
        $portal->table('employees_reimbursements')->insert(['employee_id' => $employee->id, 'reimbursement_id' => $itemId]);
        $portal->table('attachments')->insert([
            'table_name' => 'reimbursements', 'record_id' => $itemId, 'field_name' => 'receipts',
            'file_url' => 'https://res.cloudinary.com/demo/image/upload/receipt.jpg', 'file_name' => 'receipt.jpg',
        ]);
        $actionId = $this->ledgerAdd($itemId, 'edit', 'requests.reimbursement');

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('create', $item['outcome']);
        $this->assertSame('Completed', $item['fields']['Status']);
        $this->assertSame(40.0, $item['fields']['Cost']);
        $this->assertSame([$employee->airtable_record_id], $item['fields']['Reimburse To']);
        $this->assertSame([['url' => 'https://res.cloudinary.com/demo/image/upload/receipt.jpg', 'filename' => 'receipt.jpg']], $item['fields']['Receipts (If Applicable)']);
        $this->assertArrayNotHasKey('Reimbursement Receipt', $item['fields']);
    }

    public function test_the_check_tries_every_mapping_and_writes_nothing(): void
    {
        $this->fakeAirtable();

        $this->artisan('portal:airtable-sync --check')
            ->expectsOutputToContain('CHECK')
            ->expectsOutputToContain('requests.reimbursement')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    // One filing is one Airtable record per line; the action is done only when every line landed.
    public function test_a_report_pushes_one_record_per_line_and_settles_once_all_land(): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/*' => Http::response($this->schema()),
            'api.airtable.com/v0/*' => $this->numberedCreates(),
        ]);
        $first = $this->reportLine();
        $second = $this->reportLine();
        $actionId = $this->reportAction(['lineIds' => [$first, $second]]);

        $dry = collect(app(PortalAirtableSync::class)->run(false)['items'])->where('actionId', $actionId);
        $this->assertSame(['create', 'create'], $dry->pluck('outcome')->all());
        $this->assertNotEmpty($dry->first()['fields']['Employee No.']);

        app(PortalAirtableSync::class)->run(true);

        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
        $this->assertSame('recLINE000000001', DB::connection('portal')->table('user_reports')->where('id', $first)->value('airtable_record_id'));
        $this->assertSame('recLINE000000002', DB::connection('portal')->table('user_reports')->where('id', $second)->value('airtable_record_id'));
    }

    public function test_a_report_edit_deletes_the_records_of_the_lines_it_dropped(): void
    {
        $this->fakeAirtable();
        $kept = $this->reportLine('recKEPT000000001');
        $actionId = $this->reportAction(['lineIds' => [$kept], 'removedAirtableIds' => ['recGONE0000000001']], 'edit');

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        $this->assertEqualsCanonicalizing(['synced', 'deleted'], $units->pluck('outcome')->all());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/recGONE0000000001'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/recKEPT000000001'));
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // A whole-report delete removes every line Airtable held, found in the Recycle Bin snapshot.
    public function test_a_report_delete_removes_each_line_airtable_held(): void
    {
        $this->fakeGone(exists: false);
        $actionId = app(CoreLedger::class)->recycleDeleted(
            CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-daily', 'portal.user_reports', '2026-10-05-daily',
            ['id' => '2026-10-05-daily', 'lines' => [['id' => 1, 'airtable_record_id' => 'recLINE0000000001'], ['id' => 2, 'airtable_record_id' => null]]],
            'reports.submitted', 1, null,
        )['action_id'];

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        // Only the line Airtable held is deleted, and a record already gone counts as deleted.
        $this->assertSame(['deleted'], $units->pluck('outcome')->all());
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    public function test_a_line_replaced_by_a_later_edit_is_superseded_not_failed(): void
    {
        $this->fakeAirtable();
        $actionId = $this->reportAction(['lineIds' => [99999999]]);
        $this->reportAction(['lineIds' => [$this->reportLine()]], 'edit');

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        $this->assertSame(['superseded'], $units->pluck('outcome')->all());
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // With no later action to explain it, a missing line may be a report still committing: retry it.
    public function test_a_missing_line_nothing_explains_is_retried_not_settled(): void
    {
        $this->fakeAirtable();
        $actionId = $this->reportAction(['lineIds' => [99999998]]);

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        $this->assertSame(['failed'], $units->pluck('outcome')->all());
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // The form never writes these report columns, so an edit reusing a record must not erase them.
    public function test_a_report_update_leaves_the_columns_airtable_keeps_alone(): void
    {
        $this->fakeAirtable();
        $lineId = $this->reportLine('recKEEP000000001');
        $actionId = $this->reportAction(['lineIds' => [$lineId]], 'edit');

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        foreach (['IsSupport', 'Actual Work Date', 'End date', 'Duration', 'Approver Remarks', 'Project', 'Activity', 'Earn Code'] as $kept) {
            $this->assertArrayNotHasKey($kept, $item['fields']);
        }
        // Late-submission approval is the form's own now: Pending on filing, back to Pending on an edit.
        $this->assertArrayHasKey('LateSubmission Approval', $item['fields']);
    }

    // SQL never imported every receipt, so an update with none must not erase Airtable's.
    public function test_an_update_never_sends_an_empty_receipt_or_link_list(): void
    {
        $this->fakeAirtable();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'airtable_record_id' => 'recPAID000000001', 'reimb_date' => '2026-10-05', 'item' => 'Ink', 'cost' => 10, 'qty' => 1, 'status' => 'Approved',
        ]);
        $actionId = $this->ledgerAdd($itemId, 'edit', 'requests.reimbursement');

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertArrayNotHasKey('Reimbursement Receipt', $item['fields']);
        $this->assertArrayNotHasKey('Receipts (If Applicable)', $item['fields']);
        $this->assertArrayNotHasKey('Reimburse To', $item['fields']);
    }

    // Airtable answers "gone" and "not allowed" with one 403; a read tells them apart.
    public function test_a_record_deleted_in_airtable_is_rebuilt_from_sql(): void
    {
        $this->fakeGone(exists: false);
        $requestId = $this->leaveRow('Pending', 'Vacation Leave', 'recGONE0000000001');
        $actionId = $this->ledgerAdd($requestId, 'edit');

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('synced', $item['outcome']);
        $this->assertSame('recNEW0000000009', DB::connection('portal')->table('requests')->where('id', $requestId)->value('airtable_record_id'));
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    public function test_a_403_on_a_record_that_still_exists_is_a_real_failure(): void
    {
        $this->fakeGone(exists: true);
        $requestId = $this->leaveRow('Pending', 'Vacation Leave', 'recHELD0000000001');
        $actionId = $this->ledgerAdd($requestId, 'edit');

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('failed', $item['outcome']);
        $this->assertSame('recHELD0000000001', DB::connection('portal')->table('requests')->where('id', $requestId)->value('airtable_record_id'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_deleting_a_record_airtable_already_lost_counts_as_deleted(): void
    {
        $this->fakeGone(exists: false);
        $actionId = app(CoreLedger::class)->recycleDeleted(CoreLedger::PRODUCT_PORTAL, '1:2026-10-08-daily', 'portal.user_reports', '2026-10-08-daily',
            ['lines' => [['id' => 3, 'airtable_record_id' => 'recGONE0000000002']]], 'reports.submitted', 1, null)['action_id'];

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        $this->assertSame(['deleted'], $units->pluck('outcome')->all());
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // The admin grid ledgers under the bare table name; its correction reaches Airtable column by column.
    public function test_a_grid_edit_of_a_request_sends_only_that_column(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Pending', 'Vacation Leave', 'recGRID000000001');
        $gridEdit = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['fields' => ['reason' => 'Fixed typo']], 'requests', (string) $requestId);
        DB::connection('portal')->table('requests')->where('id', $requestId)->update(['reason' => 'Fixed typo']);

        $grid = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $gridEdit);
        $this->assertIsArray($grid);
        $this->assertSame(['Reason' => 'Fixed typo'], $grid['fields']);

        // A typo fix never deletes a record (2026-10-20): only the edit that cancelled the request takes it out of Airtable.
        DB::connection('portal')->table('requests')->where('id', $requestId)->update(['status' => 'Cancelled']);
        $cancel = $this->ledgerAdd($requestId, 'edit');
        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);
        $this->assertSame(['Reason' => 'Fixed typo'], $items->firstWhere('actionId', $gridEdit)['fields']);
        $this->assertSame('discard', $items->firstWhere('actionId', $cancel)['outcome'] ?? null);
    }

    public function test_one_failed_line_leaves_the_whole_report_unsynced(): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/*' => Http::response($this->schema()),
            'api.airtable.com/v0/*' => $this->numberedCreates(failFrom: 2),
        ]);
        $actionId = $this->reportAction(['lineIds' => [$this->reportLine(), $this->reportLine()]]);

        app(PortalAirtableSync::class)->run(true);

        $action = Action::query()->findOrFail($actionId);
        $this->assertNull($action->synced_at);
        $this->assertSame(1, (int) $action->attempts);
        $this->assertStringContainsString('INVALID_REQUEST', (string) $action->last_error);
    }

    // Only a date field gets trimmed; a reason that merely starts with a date is sent whole.
    public function test_a_text_value_that_starts_with_a_date_is_not_truncated(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        DB::connection('portal')->table('requests')->where('id', $requestId)->update(['reason' => '2026-10-05 was the only free day']);
        $actionId = $this->ledgerAdd($requestId);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('2026-10-05 was the only free day', $item['fields']['Reason']);
        $this->assertSame('2026-10-05', $item['fields']['Date']);
    }

    // An exact option wins; a number-stripped match is used only when it is unambiguous.
    public function test_a_numbered_option_is_never_confused_with_its_sibling(): void
    {
        $schema = $this->schema();
        $schema['tables'][0]['fields'][4]['options']['choices'] = [['name' => '1 Vacation Leave'], ['name' => '2 Vacation Leave']];
        $this->fakeAirtable($schema);
        $exact = $this->ledgerAdd($this->leaveRow('Pending', '2 Vacation Leave'));
        $ambiguous = $this->ledgerAdd($this->leaveRow('Pending', '01 Vacation Leave'));

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        // Ambiguous means neither sibling: the value is left out with a note, never guessed at.
        $this->assertSame('2 Vacation Leave', $items->firstWhere('actionId', $exact)['fields']['Category'] ?? null);
        $this->assertSame('create', $items->firstWhere('actionId', $ambiguous)['outcome'] ?? null);
        $this->assertArrayNotHasKey('Category', $items->firstWhere('actionId', $ambiguous)['fields']);
    }

    // HR edits Employees in Airtable, so an update sends only the columns this action changed.
    public function test_an_employee_update_sends_only_the_columns_the_action_changed(): void
    {
        $this->fakeAirtable();
        $employee = DB::connection('portal')->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->first();
        $this->assertNotNull($employee);
        $ledger = app(CoreLedger::class);
        $role = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['fields' => ['role' => 'Admin']], 'employees', (string) $employee->id);
        $unmapped = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['fields' => ['is_developer' => 1]], 'employees', (string) $employee->id);

        $items = collect(app(PortalAirtableSync::class)->run(true)['items']);

        $roleItem = $items->firstWhere('actionId', $role);
        $this->assertIsArray($roleItem);
        $this->assertSame(['Role'], array_keys($roleItem['fields']));
        // Not collapsed: each action carries its own columns, so the second is judged on its own.
        $this->assertSame('nothing', $items->firstWhere('actionId', $unmapped)['outcome'] ?? null);
        $this->assertNotNull(Action::query()->findOrFail($unmapped)->synced_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && array_keys($request['fields']) === ['Role']);
    }

    // A dropped reimbursement item removes its Airtable record; one never synced needs nothing.
    public function test_a_dropped_reimbursement_item_deletes_its_record_or_needs_nothing(): void
    {
        $this->fakeAirtable();
        $ledger = app(CoreLedger::class);
        // Created and dropped before any sync: the add finds no row, and the later delete explains why.
        $orphanAdd = $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-reimbursement-900002', 'portal.requests', ['id' => '900002'], 'requests.reimbursement', '900002', 1, null);
        $synced = $ledger->recycleDeleted(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-reimbursement-900001', 'portal.requests', '900001',
            ['id' => '900001', 'airtableRecordId' => 'recITEM000000001'], 'requests.reimbursement', 1, null)['action_id'];
        $never = $ledger->recycleDeleted(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-reimbursement-900002', 'portal.requests', '900002',
            ['id' => '900002', 'airtableRecordId' => null], 'requests.reimbursement', 1, null)['action_id'];

        $items = collect(app(PortalAirtableSync::class)->run(true)['items']);

        $this->assertSame('deleted', $items->firstWhere('actionId', $synced)['outcome'] ?? null);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/recITEM000000001'));
        $this->assertSame('nothing', $items->firstWhere('actionId', $never)['outcome'] ?? null);
        $this->assertSame('superseded', $items->firstWhere('actionId', $orphanAdd)['outcome'] ?? null);
        foreach ([$synced, $never, $orphanAdd] as $actionId) {
            $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
        }
    }

    // Issues & Requests live in the portal and Jira (2026-10-21): one deleted and restored settles with nothing to send.
    public function test_an_issue_deleted_and_restored_settles_with_nothing_to_send(): void
    {
        $this->fakeAirtable();
        $ledger = app(CoreLedger::class);
        $key = CoreRecycleKey::bugReport(900003);
        $deleted = $ledger->recycleDeleted(CoreLedger::PRODUCT_PORTAL, $key, 'portal.bug_reports', '900003', ['title' => 'Save does nothing', 'kind' => 'issue'], 'bug-reports', 1, null)['action_id'];
        $restored = $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $key, 'portal.bug_reports', ['bugReportId' => 900003], 'bug-reports', '900003', 1, null);

        $items = collect(app(PortalAirtableSync::class)->run(true)['items']);

        foreach ([$deleted, $restored] as $actionId) {
            $this->assertSame('nothing', $items->firstWhere('actionId', $actionId)['outcome'] ?? null);
            $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
        }
    }

    // A report deleted before it ever synced has nothing to remove, but must still settle.
    public function test_a_report_deleted_before_it_synced_settles_and_an_old_snapshot_waits(): void
    {
        $this->fakeAirtable();
        $ledger = app(CoreLedger::class);
        $never = $ledger->recycleDeleted(CoreLedger::PRODUCT_PORTAL, '1:2026-10-06-daily', 'portal.user_reports', '2026-10-06-daily',
            ['lines' => [['id' => 1, 'airtable_record_id' => null]]], 'reports.submitted', 1, null)['action_id'];
        $old = $ledger->recycleDeleted(CoreLedger::PRODUCT_PORTAL, '1:2026-10-07-daily', 'portal.user_reports', '2026-10-07-daily',
            ['lines' => [['id' => 2]]], 'reports.submitted', 1, null)['action_id'];

        $items = collect(app(PortalAirtableSync::class)->run(true)['items']);

        $this->assertSame('nothing', $items->firstWhere('actionId', $never)['outcome'] ?? null);
        $this->assertNotNull(Action::query()->findOrFail($never)->synced_at);
        // A snapshot from before Airtable ids were recorded cannot say what to delete: it waits.
        $this->assertSame('skipped', $items->firstWhere('actionId', $old)['outcome'] ?? null);
        $this->assertNull(Action::query()->findOrFail($old)->synced_at);
    }

    // A record SQL could not keep the id of is removed again, so the retry cannot duplicate it.
    public function test_a_create_whose_id_cannot_be_written_back_is_undone(): void
    {
        $this->fakeAirtable();
        // Another row already holds the id the fake returns, so the UNIQUE write-back fails.
        $this->leaveRow('Pending', 'Vacation Leave', 'recNEW0000000001');
        $actionId = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('failed', $item['outcome']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/recNEW0000000001'));
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // Airtable replaces the whole attachment list, so one file it cannot fetch leaves the field alone.
    public function test_an_attachment_list_with_an_unfetchable_file_is_not_sent(): void
    {
        $this->fakeAirtable();
        $portal = DB::connection('portal');
        $itemId = (int) $portal->table('reimbursements')->insertGetId(['reimb_date' => '2026-10-05', 'item' => 'Ink', 'cost' => 10, 'qty' => 1, 'status' => 'Pending']);
        $portal->table('attachments')->insert([
            ['table_name' => 'reimbursements', 'record_id' => $itemId, 'field_name' => 'receipts', 'file_url' => 'https://res.cloudinary.com/demo/a.jpg', 'file_name' => 'a.jpg'],
            ['table_name' => 'reimbursements', 'record_id' => $itemId, 'field_name' => 'receipts', 'file_url' => 'C:/local/b.jpg', 'file_name' => 'b.jpg'],
        ]);
        $actionId = $this->ledgerAdd($itemId, 'edit', 'requests.reimbursement');

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertArrayNotHasKey('Receipts (If Applicable)', $item['fields']);
    }

    public function test_the_command_reports_an_unreachable_airtable_without_a_stack_trace(): void
    {
        config(['services.airtable.token' => null]);

        $this->artisan('portal:airtable-sync')
            ->expectsOutputToContain('Airtable sync stopped: AIRTABLE_TOKEN is not set.')
            ->assertExitCode(1);
    }

    // A receipt the member removed in the portal is removed in Airtable too; one SQL never had is not.
    public function test_a_receipt_the_member_removed_is_cleared_in_airtable(): void
    {
        $this->fakeAirtable();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'airtable_record_id' => 'recRCPT0000000001', 'reimb_date' => '2026-10-05', 'item' => 'Ink', 'cost' => 10, 'qty' => 1, 'status' => 'Pending',
        ]);
        $ledger = app(CoreLedger::class);
        // Filed with a receipt, then saved again without one: the ledger records both.
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-reimbursement-'.$itemId, 'portal.requests',
            ['id' => (string) $itemId, 'receipt' => ['fileUrl' => 'https://res.cloudinary.com/demo/a.jpg']], 'requests.reimbursement', (string) $itemId, 1, null);
        Action::query()->where('record_id', (string) $itemId)->update(['synced_at' => now()]);
        $removal = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests',
            ['id' => (string) $itemId, 'receipt' => null], 'requests.reimbursement', (string) $itemId);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $removal);

        $this->assertIsArray($item);
        $this->assertSame([], $item['fields']['Receipts (If Applicable)']);
        // The payout receipt was never removed by the portal, so it is still left alone.
        $this->assertArrayNotHasKey('Reimbursement Receipt', $item['fields']);
    }

    // The reported ask (2026-10-14, user): the daily-report review drives Approval, on every line it decided, and nothing else.
    public function test_a_flag_decision_sends_approval_on_the_lines_it_decided(): void
    {
        $this->fakeAirtable();
        $lineId = $this->reportLine('recLINE0000000051');
        DB::connection('portal')->table('user_reports')->where('id', $lineId)->update(['approval' => 'Rejected']);
        $flag = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.user_reports',
            ['id' => (string) $lineId, 'flagStatus' => 'Rejected', 'userReportIds' => [$lineId], 'fields' => ['approval' => 'Rejected']], 'reports.flagged', (string) $lineId);

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $flag);

        $this->assertIsArray($item);
        $this->assertSame('synced', $item['outcome']);
        $this->assertSame(['Approval' => 'Rejected'], $item['fields']);
        $this->assertNotNull(Action::query()->findOrFail($flag)->synced_at);
    }

    // Allowed activities go one row per Job Type; a department's own list is named with it in brackets.
    public function test_a_departments_allowed_activities_go_as_its_bracketed_job_type(): void
    {
        $schema = $this->schema();
        $schema['tables'][] = ['name' => 'Job Type Allowed Activities', 'fields' => [
            ['name' => 'Job Type', 'type' => 'singleLineText'],
            ['name' => 'Activity Code ID Initials', 'type' => 'multilineText'],
        ]];
        $this->fakeAirtable($schema);
        $portal = DB::connection('portal');
        $shared = (int) $portal->table('job_type_allowed_activities')->insertGetId(['job_type' => 'Sync Type', 'department' => null, 'activity_code_id_initials' => '80']);
        $own = (int) $portal->table('job_type_allowed_activities')->insertGetId(['job_type' => 'Sync Type', 'department' => 'MIS', 'activity_code_id_initials' => '50,51']);
        $ledger = app(CoreLedger::class);
        $first = $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'job_type_allowed_activities:'.$shared, 'portal.job_type_allowed_activities', ['id' => (string) $shared], 'references.allowed_activities', (string) $shared, 1, null);
        $second = $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'job_type_allowed_activities:'.$own, 'portal.job_type_allowed_activities', ['id' => (string) $own], 'references.allowed_activities', (string) $own, 1, null);

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        $this->assertSame(['Job Type' => 'Sync Type', 'Activity Code ID Initials' => '80'], $items->firstWhere('actionId', $first)['fields']);
        $this->assertSame(['Job Type' => 'Sync Type (MIS)', 'Activity Code ID Initials' => '50,51'], $items->firstWhere('actionId', $second)['fields']);
    }

    // A document slot the portal changed goes, emptied when the portal removed its file; other slots stay alone.
    public function test_a_changed_document_slot_is_sent_and_a_removed_one_goes_empty(): void
    {
        $schema = $this->schema();
        $schema['tables'][2]['fields'][] = ['name' => 'Photo', 'type' => 'multipleAttachments'];
        $schema['tables'][2]['fields'][] = ['name' => 'Tax Identification', 'type' => 'multipleAttachments'];
        $this->fakeAirtable($schema);
        $portal = DB::connection('portal');
        $employeeId = (int) $portal->table('employees')->insertGetId(['airtable_record_id' => 'recEMPL0000000061', 'id_no' => '900000-0061', 'first_name' => 'Doc', 'last_name' => 'Holder']);
        $portal->table('attachments')->insert(['table_name' => 'employees', 'record_id' => $employeeId, 'field_name' => 'photo', 'file_url' => 'https://res.cloudinary.com/demo/me.jpg', 'file_name' => 'me.jpg']);
        $ledger = app(CoreLedger::class);
        $uploaded = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['id' => (string) $employeeId, 'columns' => [], 'doc_photo' => true], 'employees', (string) $employeeId, null, 1, null);
        $removed = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['id' => (string) $employeeId, 'columns' => [], 'doc_tax_identification' => true], 'employees', (string) $employeeId, null, 1, null);

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        // Changed-only actions are not collapsed: each carries its own slot, judged with every pending one.
        $fields = $items->firstWhere('actionId', $uploaded)['fields'];
        $this->assertSame([['url' => 'https://res.cloudinary.com/demo/me.jpg', 'filename' => 'me.jpg']], $fields['Photo']);
        $this->assertSame([], $fields['Tax Identification']);
        $this->assertSame([], $items->firstWhere('actionId', $removed)['fields']['Tax Identification']);
    }

    // The base's Employees has a National ID field, and the portal a national_id slot (2026-10-20).
    public function test_a_national_id_upload_is_sent(): void
    {
        $schema = $this->schema();
        $schema['tables'][2]['fields'][] = ['name' => 'National ID', 'type' => 'multipleAttachments'];
        $this->fakeAirtable($schema);
        $portal = DB::connection('portal');
        $employeeId = (int) $portal->table('employees')->insertGetId(['airtable_record_id' => 'recEMPL0000000062', 'id_no' => '900000-0062', 'first_name' => 'Card', 'last_name' => 'Holder']);
        $portal->table('attachments')->insert(['table_name' => 'employees', 'record_id' => $employeeId, 'field_name' => 'national_id', 'file_url' => 'https://res.cloudinary.com/demo/id.jpg', 'file_name' => 'id.jpg']);
        $actionId = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['id' => (string) $employeeId, 'columns' => [], 'doc_national_id' => true], 'employees', (string) $employeeId, null, 1, null);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame([['url' => 'https://res.cloudinary.com/demo/id.jpg', 'filename' => 'id.jpg']], $item['fields']['National ID']);
    }

    // The ledger strips bank and government keys from `fields`; the grid's `columns` list still names them (2026-10-20).
    public function test_a_grid_edit_of_a_private_column_is_sent(): void
    {
        $schema = $this->schema();
        $schema['tables'][] = ['name' => 'New Employee Data', 'fields' => [['name' => 'Bank Account Number', 'type' => 'singleLineText']]];
        $this->fakeAirtable($schema);
        $rowId = (int) DB::connection('portal')->table('new_employee_data')->insertGetId(['airtable_record_id' => 'recNEWE0000000071', 'first_name' => 'Grid', 'last_name' => 'Edit', 'bank_account_number' => '0012345678']);
        $actionId = app(CoreLedger::class)->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            'portal.new_employee_data',
            ['table' => 'new_employee_data', 'key' => 'id', 'columns' => ['bank_account_number'], 'fields' => ['bank_account_number' => '0012345678'], 'changes' => ['bank_account_number' => ['from' => null, 'to' => '0012345678']]],
            'new_employee_data',
            (string) $rowId,
        );

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame('update', $item['outcome']);
        $this->assertSame(['Bank Account Number' => '0012345678'], $item['fields']);
    }

    // The grid follows the portal's own path (2026-10-20): Cancelled leaves Airtable, an unoffered leave kind is left out.
    public function test_a_grid_cancel_discards_and_an_unoffered_category_is_left_out(): void
    {
        $this->fakeAirtable();
        $ledger = app(CoreLedger::class);
        $cancelled = $this->leaveRow('Cancelled', 'Vacation Leave', 'recGRIDCANCEL0001');
        $cancel = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['table' => 'requests', 'key' => 'id', 'columns' => ['status'], 'fields' => ['status' => 'Cancelled']], 'requests', (string) $cancelled);
        $emergency = $this->leaveRow('Pending', '03 Emergency Leave', 'recGRIDLEAVE00001');
        $category = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['table' => 'requests', 'key' => 'id', 'columns' => ['category'], 'fields' => ['category' => '03 Emergency Leave']], 'requests', (string) $emergency);

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        $this->assertSame('discard', $items->firstWhere('actionId', $cancel)['outcome']);
        // Nothing else changed, so there is no update to send: an empty one would be refused.
        $kept = $items->firstWhere('actionId', $category);
        $this->assertSame('nothing', $kept['outcome']);
        $this->assertStringContainsString('"Category" left out', implode(' ', $kept['problems']));
    }

    // A department References adds, which the base does not offer, is left out instead of holding the project back.
    public function test_a_project_department_the_base_lacks_is_left_out_not_blocking(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ0000000091', 'department' => 'Research']);
        $actionId = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.projects', ['fields' => ['department' => 'Research', 'project_name' => '900001 Sync Test']], 'projects', (string) $projectId);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame('update', $item['outcome']);
        $this->assertSame(['Project Name' => '900001 Sync Test'], $item['fields']);
        $this->assertStringContainsString('"Department" left out', implode(' ', $item['problems']));
    }

    // A try that did not land waits 2^(n-1) minutes from that try, up to six hours, but only on a scheduled run.
    public function test_the_schedule_backs_off_from_the_last_try(): void
    {
        $this->fakeAirtable();
        $actionId = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));
        $planned = fn (bool $backoff): bool => collect(app(PortalAirtableSync::class)->run(false, 0, false, null, $backoff)['items'])->contains('actionId', $actionId);

        // Three tries: the fourth comes 4 minutes after the third, however old the action is.
        Action::query()->whereKey($actionId)->update(['attempts' => 3, 'created_at' => now()->subDays(30), 'attempted_at' => now()->subMinutes(3)]);
        $this->assertFalse($planned(true));
        $this->assertTrue($planned(false));
        Action::query()->whereKey($actionId)->update(['attempted_at' => now()->subMinutes(5)]);
        $this->assertTrue($planned(true));
        // Past nine tries it is every six hours.
        Action::query()->whereKey($actionId)->update(['attempts' => 12, 'attempted_at' => now()->subMinutes(359)]);
        $this->assertFalse($planned(true));
        Action::query()->whereKey($actionId)->update(['attempted_at' => now()->subMinutes(361)]);
        $this->assertTrue($planned(true));
        // A row written before attempted_at existed waits from when it was written.
        Action::query()->whereKey($actionId)->update(['attempts' => 1, 'attempted_at' => null, 'created_at' => now()->subSeconds(30)]);
        $this->assertFalse($planned(true));
    }

    // An idle scheduled minute asks Airtable nothing at all (2026-10-20): plans that count calls are not spent.
    public function test_an_idle_scheduled_run_makes_no_airtable_call(): void
    {
        $this->fakeAirtable();

        $this->artisan('portal:airtable-sync', ['--send' => true, '--backoff' => true])
            ->expectsOutputToContain('Nothing due.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    // What was ledgered before an import names renumbered rows, so it is settled unsent on request.
    public function test_skip_through_settles_older_actions_without_sending(): void
    {
        $this->fakeAirtable();
        $older = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));
        $newer = $this->ledgerAdd($this->leaveRow('Pending', 'Sick Leave'));

        $this->artisan('portal:airtable-sync', ['--skip-through' => (string) $older])
            ->expectsOutputToContain('1 unsynced actions up to #'.$older.' settled without sending')
            ->assertExitCode(0);
        $this->artisan('portal:airtable-sync', ['--skip-through' => 'yesterday'])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNotNull(Action::query()->findOrFail($older)->synced_at);
        $this->assertStringContainsString('Not sent', (string) Action::query()->findOrFail($older)->last_error);
        $this->assertNull(Action::query()->findOrFail($newer)->synced_at);
    }

    // A run past its budget takes no new action; what it did not reach goes next run.
    public function test_a_run_past_its_budget_stops_taking_actions(): void
    {
        $this->fakeAirtable();
        $actionId = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));

        $report = app(PortalAirtableSync::class)->run(true, 0, false, null, false, 0);

        $this->assertTrue($report['stopped']);
        $this->assertSame([], $report['items']);
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // Off until AIRTABLE_SYNC_SCHEDULED is set, so setting the keys alone never starts a send.
    public function test_the_scheduled_send_waits_for_its_switch(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'portal:airtable-sync --send --backoff'));
        $this->assertNotNull($event);

        config(['services.airtable.scheduled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['services.airtable.scheduled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
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

    // A report ledgered before line ids were recorded cannot be planned, so it is left, never failed.
    public function test_a_report_action_carrying_no_line_ids_is_skipped(): void
    {
        $this->fakeAirtable();
        $actionId = $this->reportAction([]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('skipped', $item['outcome']);
        $this->assertStringContainsString('no line ids', implode(' ', $item['problems']));
    }

    // The grid only ever changes a record Airtable holds: a row it has never seen cannot be rebuilt
    // from a changed column, so the action is blocked rather than creating a half-empty record.
    public function test_a_changed_only_row_airtable_has_never_seen_is_blocked(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $actionId = app(CoreLedger::class)->recordEdit(
            CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['fields' => ['status' => 'Approved']], 'requests', (string) $requestId,
        );

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('blocked', $item['outcome']);
        $this->assertStringContainsString('Only changes to records Airtable already has', implode(' ', $item['problems']));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    // The reported gap (2026-10-14): a person the portal adds is created in Airtable whole, so what
    // links them can follow; a Job Title Airtable does not offer is left out rather than holding them back.
    public function test_a_person_the_portal_adds_is_created_whole(): void
    {
        $this->fakeAirtable();
        $employeeId = (int) DB::connection('portal')->table('employees')->insertGetId([
            'id_no' => '900000-0002', 'first_name' => 'New', 'last_name' => 'Hire', 'email' => 'new.hire@example.com',
            'role' => 'User', 'status' => 'Active', 'department' => 'MIS', 'job_title' => 'Chief Vibes Officer',
        ]);
        $actionId = app(CoreLedger::class)->recordAdd(CoreLedger::PRODUCT_PORTAL, 'employees:'.$employeeId, 'portal.employees', ['id' => (string) $employeeId], 'employees', (string) $employeeId, 1, null);

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('synced', $item['outcome']);
        $this->assertSame('New', $item['fields']['First Name']);
        $this->assertArrayNotHasKey('Job Title', $item['fields']);
        $this->assertStringContainsString('"Job Title" left out', implode(' ', $item['problems']));
        $this->assertSame('recNEW0000000001', DB::connection('portal')->table('employees')->where('id', $employeeId)->value('airtable_record_id'));
    }

    // A private column is named in a plain list: its key would be stripped from the ledger, its value is read from SQL.
    public function test_a_column_list_names_private_columns_without_carrying_their_values(): void
    {
        $this->fakeAirtable();
        $employeeId = (int) DB::connection('portal')->table('employees')->insertGetId([
            'airtable_record_id' => 'recEMPL0000000031', 'id_no' => '900000-0003', 'first_name' => 'Kept', 'last_name' => 'Private', 'email' => 'kept@example.com',
        ]);
        $actionId = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['columns' => ['email']], 'employees', (string) $employeeId, null, 1, null);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame(['Email' => 'kept@example.com'], $item['fields']);
    }

    // Manage / References (2026-10-14): a client added, changed and removed in the portal follows in Airtable.
    public function test_a_client_is_created_patched_and_deleted(): void
    {
        $this->fakeAirtable();
        $ledger = app(CoreLedger::class);
        $portal = DB::connection('portal');
        $clientId = (int) $portal->table('clients')->insertGetId(['name' => 'Sync Client', 'client_id' => 'C-900']);
        $added = $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'clients:'.$clientId, 'portal.clients', ['id' => (string) $clientId, 'fields' => ['name' => 'Sync Client']], 'references.clients', (string) $clientId, 1, null);
        $kept = (int) $portal->table('clients')->insertGetId(['airtable_record_id' => 'recCLNT0000000041', 'name' => 'Old Name', 'client_id' => 'C-901']);
        $renamed = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.clients', ['id' => (string) $kept, 'fields' => ['name' => 'New Name']], 'references.clients', (string) $kept, null, 1, null);
        $portal->table('clients')->where('id', $kept)->update(['name' => 'New Name']);
        $removed = $ledger->recordDelete(CoreLedger::PRODUCT_PORTAL, 'portal.clients', ['id' => '900902', 'airtableRecordId' => 'recCLNT0000000042'], 'references.clients', '900902', 1, null);

        $items = collect(app(PortalAirtableSync::class)->run(false, 0)['items']);

        $this->assertSame('create', $items->firstWhere('actionId', $added)['outcome']);
        $this->assertSame(['Name' => 'Sync Client', 'ClientID' => 'C-900'], $items->firstWhere('actionId', $added)['fields']);
        $this->assertSame(['Name' => 'New Name'], $items->firstWhere('actionId', $renamed)['fields']);
        $this->assertSame(['delete', 'recCLNT0000000042'], [$items->firstWhere('actionId', $removed)['outcome'], $items->firstWhere('actionId', $removed)['airtableId']]);
    }

    // Every Trail history row the portal writes is added to Airtable, linked to its project (2026-10-14, user).
    public function test_a_trail_row_is_created_linked_to_its_project(): void
    {
        $schema = $this->schema();
        $schema['tables'][] = ['name' => 'Project Action History', 'fields' => [
            ['name' => 'ActionName', 'type' => 'singleLineText'],
            ['name' => 'CreatedBy', 'type' => 'singleLineText'],
            ['name' => 'Remarks', 'type' => 'singleLineText'],
            ['name' => 'Project', 'type' => 'multipleRecordLinks'],
        ]];
        $this->fakeAirtable($schema);
        $portal = DB::connection('portal');
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ0000000071']);
        $trailId = (int) $portal->table('project_action_history')->insertGetId(['action_name' => 'Project Edited', 'created_by' => 'Sync Tester', 'remarks' => 'Status: Ongoing to Done', 'created_at' => now()]);
        $portal->table('projects_action_history')->insert(['project_id' => $projectId, 'project_action_id' => $trailId]);
        $actionId = app(CoreLedger::class)->recordAdd(CoreLedger::PRODUCT_PORTAL, 'project_action_history:'.$trailId, 'portal.project_action_history', ['id' => (string) $trailId], 'projects.trail', (string) $trailId, 1, null);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame('create', $item['outcome']);
        $this->assertSame(['ActionName' => 'Project Edited', 'CreatedBy' => 'Sync Tester', 'Remarks' => 'Status: Ongoing to Done', 'Project' => ['recPROJ0000000071']], $item['fields']);
    }

    // A submitted registration is created in Airtable whole, and promotion sends the row again with its
    // ID No. and approval (2026-10-14, user).
    public function test_a_registration_is_created_and_promotion_updates_it(): void
    {
        $schema = $this->schema();
        $schema['tables'][] = ['name' => 'New Employee Data', 'fields' => array_map(
            static fn (string $name): array => ['name' => $name, 'type' => 'singleLineText'],
            array_values(AirtableSyncMap::for('onboarding.employee_data')['fields']),
        )];
        $this->fakeAirtable($schema);
        $portal = DB::connection('portal');
        $dataId = (int) $portal->table('new_employee_data')->insertGetId([
            'first_name' => 'Liza', 'last_name' => 'Reyes', 'personal_email' => 'liza.personal@example.test',
            'status' => 'Active', 'data_approval' => 'Pending',
        ]);
        $addId = app(CoreLedger::class)->recordAdd(CoreLedger::PRODUCT_PORTAL, 'new_employee_data:'.$dataId, 'portal.new_employee_data', ['id' => (string) $dataId], 'onboarding.employee_data', (string) $dataId, 1, null);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $addId);
        $this->assertSame('create', $item['outcome']);
        $this->assertSame(['First Name' => 'Liza', 'Last Name' => 'Reyes', 'Status' => 'Active', 'Personal E-Mail' => 'liza.personal@example.test', 'Data Approval' => 'Pending'], $item['fields']);

        app(PortalAirtableSync::class)->run(true);

        // Promoting them writes their ID No. and approval; the portal owns the row, so it goes whole.
        $portal->table('new_employee_data')->where('id', $dataId)->update(['id_no' => '260701-0099', 'data_approval' => 'Approved']);
        $editId = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.new_employee_data', ['id' => (string) $dataId], 'onboarding.employee_data', (string) $dataId, null, 1, null);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $editId);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame('260701-0099', $item['fields']['ID No.']);
        $this->assertSame('Approved', $item['fields']['Data Approval']);
        $this->assertSame('Liza', $item['fields']['First Name']);
    }

    // The admin grid's bare table names reach every mirrored table it edits, column by column (2026-10-14, user).
    public function test_a_grid_edit_of_a_mirrored_table_sends_only_that_column(): void
    {
        $this->fakeAirtable();
        $clientId = (int) DB::connection('portal')->table('clients')->insertGetId(['airtable_record_id' => 'recCLNT0000000081', 'name' => 'Grid Client', 'client_id' => 'C-981', 'notes' => 'Edited in the grid']);
        $actionId = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.clients', ['table' => 'clients', 'key' => 'id', 'fields' => ['notes' => 'Edited in the grid']], 'clients', (string) $clientId);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame(['Notes' => 'Edited in the grid'], $item['fields']);
    }

    // PHP keeps only the last of two equal array keys, silently: one resource must never shadow another.
    public function test_no_resource_is_mapped_twice(): void
    {
        $source = (string) file_get_contents(app_path('Support/Airtable/AirtableSyncMap.php'));
        $block = substr($source, (int) strpos($source, 'private const RESOURCES = ['), (int) strpos($source, 'private const PORTAL_ONLY') - (int) strpos($source, 'private const RESOURCES = ['));
        preg_match_all("/^        '([a-z_.0-9]+)' => /m", $block, $keys);

        $this->assertSame(count($keys[1]), count(array_unique($keys[1])));
        $this->assertCount(count($keys[1]), AirtableSyncMap::resources());
    }

    // The grid ledgers an edit as a diff under `changes` as well as a flat `fields` map; either way
    // it names the columns, and only those reach Airtable.
    public function test_a_changes_diff_names_the_columns_to_send(): void
    {
        $this->fakeAirtable();
        $employeeId = (int) DB::connection('portal')->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->value('id');
        $actionId = app(CoreLedger::class)->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            'portal.employees',
            ['changes' => ['email' => ['from' => 'old@ikaikabim.com', 'to' => 'new@ikaikabim.com']]],
            'employees',
            (string) $employeeId,
        );

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame(['Email'], array_keys($item['fields']));
    }

    // Two actions on one row: when the first could not go through, the later one says so rather than
    // planning a second push of the same row, and both retry together next run.
    public function test_a_later_action_on_a_row_the_first_could_not_send_is_retried_with_it(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Nonsense', 'Vacation Leave');
        $first = $this->ledgerAdd($requestId);
        $second = $this->ledgerAdd($requestId, 'edit');

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        $this->assertSame('blocked', $items->firstWhere('actionId', $first)['outcome'] ?? null);
        $later = $items->firstWhere('actionId', $second);
        $this->assertIsArray($later);
        $this->assertSame('blocked', $later['outcome']);
        $this->assertStringContainsString('retried with it next run', implode(' ', $later['problems']));
    }

    // An admin's late-submission decision is its own resource: only the two columns it decided reach
    // Airtable, and only on the lines it decided. It was unmapped, so every decision stayed skipped.
    public function test_a_late_decision_sends_only_the_two_columns_it_changed(): void
    {
        $this->fakeAirtable();
        $line = $this->reportLine('recLINE0000000001');
        // decideLate() writes the column before the ledger entry, and the sync pushes the row's state.
        DB::connection('portal')->table('user_reports')->where('id', $line)->update(['late_submission_approval' => 'Approved']);
        $actionId = app(CoreLedger::class)->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            'portal.user_reports',
            [
                'id' => '2026-10-05-late',
                'userReportIds' => [$line],
                'reportedOn' => '2026-10-05',
                'lateSubmissionApproval' => 'Approved',
                'approverRemarks' => 'Late but explained.',
                'fields' => ['late_submission_approval' => 'Approved', 'approver_remarks' => 'Late but explained.'],
            ],
            'reports.late',
            '2026-10-05-late',
            '1:2026-10-05-late',
            1,
            null,
        );

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame(['LateSubmission Approval', 'Approver Remarks'], array_keys($item['fields']));
        $this->assertSame('Approved', $item['fields']['LateSubmission Approval']);
    }

    // The portal's Add Project: a row Airtable has never seen is created whole, and the people arrive
    // as links -- the lead matched by email, members and support told apart by the role on the junction.
    public function test_a_project_the_portal_adds_is_created_whole_with_its_people_linked(): void
    {
        $this->fakeAirtable();
        $lead = DB::connection('portal')->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->first();
        $this->assertNotNull($lead);
        $projectId = $this->projectRow(['project_lead_email' => $lead->email]);
        $member = $this->linkPerson($projectId, 'member', 1);
        $support = $this->linkPerson($projectId, 'support', 2);
        $actionId = $this->ledgerProject($projectId, 'add', ['columns' => ['status' => 'Started']]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('create', $item['outcome']);
        $this->assertSame('Started', $item['fields']['Status']);
        $this->assertSame([$lead->airtable_record_id], $item['fields']['PM ID']);
        $this->assertSame([$member], $item['fields']['Project Members']);
        $this->assertSame([$support], $item['fields']['Support Members']);
    }

    // An edit sends only the columns the action changed, so a PM's own Airtable work is not re-sent over.
    public function test_a_project_edit_sends_only_the_columns_the_action_changed(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ000000001', 'status' => 'Done']);
        $actionId = $this->ledgerProject($projectId, 'edit', ['columns' => ['status' => 'Done']]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame(['Status'], array_keys($item['fields']));
    }

    // The approval queue writes four columns; its ledger entry names them as a column map, and the
    // column it also writes for its own bookkeeping (date_modified) is not mapped, so it never goes.
    public function test_a_project_approval_sends_only_the_four_columns_it_decided(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow([
            'airtable_record_id' => 'recPROJ000000002',
            'project_creation_status' => 'Approved',
            'project_creation_approver' => 'Ada Admin',
            'project_approver_email' => 'ada@ikaikabim.com',
            'project_creation_approver_remarks' => 'Fine.',
        ]);
        $actionId = $this->ledgerProject($projectId, 'edit', ['fields' => [
            'project_creation_status' => 'Approved',
            'project_creation_approver' => 'Ada Admin',
            'project_approver_email' => 'ada@ikaikabim.com',
            'project_creation_approver_remarks' => 'Fine.',
            'date_modified' => '2026-10-07 00:00:00',
        ]]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame([
            'ProjectCreationStatus',
            'ProjectCreationApprover',
            'Project Approver Email',
            'ProjectCreationApproverRemarks',
        ], array_keys($item['fields']));
    }

    // A project with no lead named sends no PM link at all: an empty value must never be matched against
    // a blank email, and an empty link list is no evidence the link should go.
    public function test_a_project_without_a_lead_sends_no_pm_link(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['project_lead_email' => null]);
        $actionId = $this->ledgerProject($projectId, 'add', ['columns' => ['status' => 'Started']]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('create', $item['outcome']);
        $this->assertArrayNotHasKey('PM ID', $item['fields']);
    }

    // A lead Airtable has never seen blocks the project: pushing without the link would drop it silently.
    public function test_a_project_whose_lead_airtable_has_not_seen_is_blocked(): void
    {
        $this->fakeAirtable();
        DB::connection('portal')->table('employees')->insert([
            'id_no' => '900000-0002', 'first_name' => 'Never', 'last_name' => 'Linked', 'email' => 'never.linked@ikaikabim.com',
        ]);
        $projectId = $this->projectRow(['project_lead_email' => 'never.linked@ikaikabim.com']);
        $actionId = $this->ledgerProject($projectId, 'add', ['columns' => ['status' => 'Started']]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('blocked', $item['outcome']);
        $this->assertStringContainsString('which Airtable does not have yet', implode(' ', $item['problems']));
    }

    // The portal soft-deletes a project into the recycle bin, so its delete never reaches Airtable: the record is
    // Airtable's to keep, and the action settles (2026-10-20) rather than coming back every scheduled minute.
    public function test_a_project_delete_settles_without_touching_airtable(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ000000003']);
        $written = app(CoreLedger::class)->recycleDeleted(
            CoreLedger::PRODUCT_PORTAL,
            '1:900001',
            'portal.projects',
            (string) $projectId,
            ['title' => '900001 Sync Test', 'kind' => 'project'],
            'projects',
            1,
            null,
        );

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $written['action_id']);

        $this->assertIsArray($item);
        $this->assertSame('nothing', $item['outcome']);
        $this->assertStringContainsString('Airtable keeps its record', implode(' ', $item['problems']));
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    // The reported bug (2026-10-14): the editor's own edit ledgers what it changed, and only that is sent.
    public function test_a_project_edit_sends_its_changed_columns_and_only_the_links_it_changed(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ0000000011', 'project_lead_email' => null]);
        $member = $this->linkPerson($projectId, 'member', 0);
        $this->linkPerson($projectId, 'support', 1);
        $actionId = $this->ledgerProject($projectId, 'edit', ['projectName' => 'Sync Test', 'fields' => ['status' => 'Done', 'memberIds' => [1]]]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame(['Status', 'Project Members'], array_keys($item['fields']));
        $this->assertSame([$member], $item['fields']['Project Members']);
    }

    // An approval re-sends no people: a member a PM added in Airtable must survive it.
    public function test_a_project_decision_sends_no_links(): void
    {
        $this->fakeAirtable();
        $lead = DB::connection('portal')->table('employees')->whereNotNull('airtable_record_id')->whereNotNull('email')->orderBy('id')->first();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ0000000012', 'project_lead_email' => $lead->email]);
        $this->linkPerson($projectId, 'member', 0);
        $actionId = $this->ledgerProject($projectId, 'edit', ['decision' => 'approved', 'fields' => ['project_creation_status' => 'Approved', 'date_modified' => '2026-10-14']]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame(['ProjectCreationStatus'], array_keys($item['fields']));
    }

    // Removing the last member is the portal's own act, so the empty list goes.
    public function test_a_link_the_portal_emptied_is_sent_empty(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ0000000013']);
        $actionId = $this->ledgerProject($projectId, 'edit', ['fields' => ['supportMemberIds' => []]]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame(['Support Members' => []], $item['fields']);
    }

    // Closing answers Airtable's three checklist questions as checkboxes.
    public function test_closing_sends_the_checklist_as_checkboxes(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['airtable_record_id' => 'recPROJ0000000014', 'status' => 'Closed', 'is_renamed_with_acc_number' => 1, 'is_ledger_moved_to_for_submission' => 1, 'is_ledger_details_updated' => 0]);
        $actionId = $this->ledgerProject($projectId, 'edit', ['fields' => ['status' => 'Closed', 'is_renamed_with_acc_number' => true, 'is_ledger_moved_to_for_submission' => true, 'is_ledger_details_updated' => false]]);

        $item = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('update', $item['outcome']);
        $this->assertSame('Closed', $item['fields']['Status']);
        $this->assertTrue($item['fields']['Is the ACC project renamed with ACC latest project number?']);
        $this->assertFalse($item['fields']['Are the details in the project ledger (MS Teams Planner) updated?']);
    }

    // A project moved to the Recycle Bin before its first sync is never created there.
    public function test_a_project_in_the_recycle_bin_is_never_created(): void
    {
        $this->fakeAirtable();
        $projectId = $this->projectRow(['deleted_at' => now()]);
        $actionId = $this->ledgerProject($projectId, 'add', ['projectName' => 'Sync Test']);

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertIsArray($item);
        $this->assertSame('nothing', $item['outcome']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // Long missing is gone: an old action whose row an edit under another resource replaced settles.
    public function test_a_row_missing_long_after_its_action_settles_as_superseded(): void
    {
        $this->fakeAirtable();
        $actionId = $this->reportAction(['lineIds' => [99999997]]);
        Action::query()->whereKey($actionId)->update(['created_at' => now()->subMinutes(20)]);

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        $this->assertSame(['superseded'], $units->pluck('outcome')->all());
        $this->assertNotNull(Action::query()->findOrFail($actionId)->synced_at);
    }

    // A blocker is kept on the action, so it shows outside a dry run, and counts a try so the schedule backs off.
    public function test_a_blocked_action_keeps_its_reason_and_counts_a_try(): void
    {
        $this->fakeAirtable();
        $requestId = $this->leaveRow('Rescheduled', 'Vacation Leave');
        $actionId = $this->ledgerAdd($requestId);

        app(PortalAirtableSync::class)->run(true);

        $action = Action::query()->findOrFail($actionId);
        $this->assertNull($action->synced_at);
        $this->assertSame(1, (int) $action->attempts);
        $this->assertNotNull($action->attempted_at);
        $this->assertStringContainsString('has no option "Rescheduled"', (string) $action->last_error);
    }

    // A create whose answer was lost may be in Airtable: it is held, and sent again only when asked.
    public function test_a_create_whose_answer_was_lost_is_held_until_retry_creates(): void
    {
        $posts = 0;
        Http::fake(function (Request $request) use (&$posts) {
            if ($request->method() !== 'POST') {
                return Http::response($this->schema());
            }
            $posts++;
            if ($posts === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['id' => 'recNEW0000000077']);
        });
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $actionId = $this->ledgerAdd($requestId);

        $first = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);
        $second = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame(['failed', 'failed'], [$first['outcome'], $second['outcome']]);
        $this->assertSame(1, $posts);
        $this->assertStringContainsString(PortalAirtableSync::UNCERTAIN_CREATE, (string) Action::query()->findOrFail($actionId)->last_error);

        $retried = collect(app(PortalAirtableSync::class)->run(true, 0, true)['items'])->firstWhere('actionId', $actionId);
        $this->assertSame('synced', $retried['outcome']);
        $this->assertSame(2, $posts);
    }

    // A held create holds its row (2026-10-20): another action on that row must not create a second record beside it.
    public function test_a_held_create_holds_every_other_action_on_its_row(): void
    {
        $posts = 0;
        Http::fake(function (Request $request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;

                return Http::response(['id' => 'recNEW0000000078']);
            }

            return Http::response($this->schema());
        });
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $held = $this->ledgerAdd($requestId);
        Action::query()->whereKey($held)->update(['last_error' => PortalAirtableSync::UNCERTAIN_CREATE, 'attempts' => 1, 'attempted_at' => now()]);
        $later = $this->ledgerAdd($requestId, 'edit');

        // The held one sits out its backoff window, so the later one comes alone: it must not create.
        $items = collect(app(PortalAirtableSync::class)->run(true, 0, false, null, true)['items']);

        $this->assertNull($items->firstWhere('actionId', $held));
        $this->assertSame('failed', $items->firstWhere('actionId', $later)['outcome']);
        $this->assertSame(0, $posts);
        $this->assertStringStartsWith(PortalAirtableSync::UNCERTAIN_CREATE, (string) Action::query()->findOrFail($later)->last_error);

        // Once someone has looked in Airtable, --retry-creates sends one record for the row, never two.
        app(PortalAirtableSync::class)->run(true, 0, true);
        $this->assertSame(1, $posts);
        $this->assertNotNull(Action::query()->findOrFail($held)->synced_at);
        $this->assertNotNull(Action::query()->findOrFail($later)->synced_at);
    }

    // The hold is written before the create goes out, so a run killed mid-call cannot create the row twice.
    public function test_a_create_is_marked_held_before_it_is_sent(): void
    {
        $requestId = $this->leaveRow('Pending', 'Vacation Leave');
        $actionId = $this->ledgerAdd($requestId);
        $markedWhileSending = null;
        Http::fake(function (Request $request) use ($actionId, &$markedWhileSending) {
            if ($request->method() !== 'POST') {
                return Http::response($this->schema());
            }
            $markedWhileSending = Action::query()->findOrFail($actionId)->last_error;

            return Http::response(['id' => 'recNEW0000000079']);
        });

        app(PortalAirtableSync::class)->run(true);

        $this->assertSame(PortalAirtableSync::UNCERTAIN_CREATE, $markedWhileSending);
        $action = Action::query()->findOrFail($actionId);
        $this->assertNotNull($action->synced_at);
        $this->assertNull($action->last_error);
    }

    // A host that could not be found or reached never got the request, so it is a plain failure, not a held create.
    public function test_a_create_that_never_left_is_retried_not_held(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                throw new ConnectionException('cURL error 6: Could not resolve host', 0, new ConnectException('Could not resolve host', new Psr7Request('POST', 'https://api.airtable.com'), null, ['errno' => 6]));
            }

            return Http::response($this->schema());
        });
        $actionId = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame('failed', $item['outcome']);
        $this->assertStringNotContainsString(PortalAirtableSync::UNCERTAIN_CREATE, (string) Action::query()->findOrFail($actionId)->last_error);
    }

    // A gateway that gave up on a create may have let it land, so that create is held like a lost answer.
    public function test_a_gateway_timeout_on_a_create_holds_it(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response('<html>Gateway Timeout</html>', 504)
            : Http::response($this->schema()));
        $actionId = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));

        app(PortalAirtableSync::class)->run(true);

        $this->assertStringStartsWith(PortalAirtableSync::UNCERTAIN_CREATE, (string) Action::query()->findOrFail($actionId)->last_error);
    }

    // A held action whose line an edit replaced is never quietly superseded: its create may have landed.
    public function test_a_held_action_whose_line_is_gone_stays_held(): void
    {
        $this->fakeAirtable();
        $actionId = $this->reportAction(['lineIds' => [99999996]]);
        Action::query()->whereKey($actionId)->update(['created_at' => now()->subMinutes(20), 'last_error' => PortalAirtableSync::UNCERTAIN_CREATE]);

        $units = collect(app(PortalAirtableSync::class)->run(true)['items'])->where('actionId', $actionId);

        $this->assertSame(['failed'], $units->pluck('outcome')->all());
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
        $superseded = collect(app(PortalAirtableSync::class)->run(true, 0, true)['items'])->where('actionId', $actionId);
        $this->assertSame(['superseded'], $superseded->pluck('outcome')->all());
    }

    // A record deleted in Airtable on a changed-only table counts as a failed try, so the schedule backs off from it.
    public function test_a_record_deleted_in_airtable_on_a_changed_only_table_backs_off(): void
    {
        $this->fakeGone(exists: false);
        $employeeId = (int) DB::connection('portal')->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->value('id');
        $actionId = app(CoreLedger::class)->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['fields' => ['role' => 'Admin']], 'employees', (string) $employeeId);

        $item = collect(app(PortalAirtableSync::class)->run(true)['items'])->firstWhere('actionId', $actionId);

        $this->assertSame('failed', $item['outcome']);
        $this->assertStringContainsString('cannot be rebuilt', implode(' ', $item['problems']));
        $this->assertSame(1, (int) Action::query()->findOrFail($actionId)->attempts);
    }

    // The schedule's output goes nowhere, so a send that did not land everything says so in the log.
    public function test_a_send_that_did_not_land_everything_is_logged(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['error' => ['type' => 'INVALID_REQUEST', 'message' => 'Nope']], 422)
            : Http::response(str_contains($request->url(), '/meta/') ? $this->schema() : ['records' => [['id' => 'recSTILL00000001']]]));
        $actionId = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));
        Action::query()->whereKey($actionId)->update(['created_at' => now()->subMinute()]);
        Log::spy();

        $this->artisan('portal:airtable-sync', ['--send' => true])->assertExitCode(1);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $counts): bool => $message === 'portal.airtable_sync.not_landed' && $counts['failed'] === 1);
    }

    // "2 Day" is not "1 Day": a plain number is part of the label, while a leading index is not.
    public function test_a_numbered_label_never_lands_on_another_number(): void
    {
        $this->fakeAirtable();
        $two = $this->ledgerAdd($this->leaveRow('Pending', '2 Day'));
        $indexed = $this->ledgerAdd($this->leaveRow('Pending', '01 Vacation Leave'));

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        // It fits no option, so it is left out with a note; it must never land on "1 Day".
        $this->assertSame('create', $items->firstWhere('actionId', $two)['outcome']);
        $this->assertArrayNotHasKey('Category', $items->firstWhere('actionId', $two)['fields']);
        $this->assertSame('Vacation Leave', $items->firstWhere('actionId', $indexed)['fields']['Category']);
    }

    // A fresh action waits a run (its row may still be committing), and failures never overflow attempts.
    public function test_a_fresh_action_waits_and_attempts_stay_capped(): void
    {
        $this->fakeAirtable();
        $fresh = $this->ledgerAdd($this->leaveRow('Pending', 'Vacation Leave'));
        $this->assertNull(collect(app(PortalAirtableSync::class)->run(false, 10)['items'])->firstWhere('actionId', $fresh));

        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['error' => ['type' => 'INVALID_REQUEST', 'message' => 'Nope']], 422)
            : Http::response($this->schema()));
        Action::query()->whereKey($fresh)->update(['attempts' => 65000]);
        app(PortalAirtableSync::class)->run(true);
        $this->assertSame(65000, (int) Action::query()->findOrFail($fresh)->attempts);
    }

    // A decision re-sends no receipt; the payout carries only its own file.
    public function test_receipts_go_on_a_create_or_when_the_action_changed_them(): void
    {
        $this->fakeAirtable();
        $portal = DB::connection('portal');
        $itemId = (int) $portal->table('reimbursements')->insertGetId(['airtable_record_id' => 'recITEM0000000021', 'reimb_date' => '2026-10-05', 'item' => 'Ink', 'cost' => 10, 'qty' => 1, 'status' => 'Approved']);
        $portal->table('attachments')->insert([
            ['table_name' => 'reimbursements', 'record_id' => $itemId, 'field_name' => 'receipts', 'file_url' => 'https://res.cloudinary.com/demo/a.jpg', 'file_name' => 'a.jpg'],
            ['table_name' => 'reimbursements', 'record_id' => $itemId, 'field_name' => 'payout_receipt', 'file_url' => 'https://res.cloudinary.com/demo/p.jpg', 'file_name' => 'p.jpg'],
        ]);
        $ledger = app(CoreLedger::class);
        $decision = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $itemId, 'status' => 'Approved'], 'requests.reimbursement', (string) $itemId, null, 1, null);
        $payout = $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $itemId, 'payoutReceipt' => ['name' => 'p.jpg']], 'requests.reimbursement', (string) $itemId, null, 1, null);

        $items = collect(app(PortalAirtableSync::class)->run(false)['items']);

        // The first action pushes the row for both, so it carries the payout the later one changed.
        $this->assertArrayNotHasKey('Receipts (If Applicable)', $items->firstWhere('actionId', $decision)['fields']);
        $this->assertSame([['url' => 'https://res.cloudinary.com/demo/p.jpg', 'filename' => 'p.jpg']], $items->firstWhere('actionId', $decision)['fields']['Reimbursement Receipt']);
        $this->assertSame('covered', $items->firstWhere('actionId', $payout)['outcome']);

        // Alone, a decision sends no file at all.
        Action::query()->whereKey($payout)->update(['synced_at' => now()]);
        $alone = collect(app(PortalAirtableSync::class)->run(false)['items'])->firstWhere('actionId', $decision);
        $this->assertArrayNotHasKey('Reimbursement Receipt', $alone['fields']);
    }

    // Production pointed at the test base would clear every airtable_record_id and re-create the rows there.
    public function test_a_send_refuses_a_base_that_holds_none_of_the_databases_records(): void
    {
        $this->fakeGone(false);
        $requestId = $this->leaveRow('Pending', 'Vacation Leave', 'recELSEWHERE00001');
        $actionId = $this->ledgerAdd($requestId, 'edit');

        $this->artisan('portal:airtable-sync', ['--send' => true])
            ->expectsOutputToContain('AIRTABLE_BASE_ID names another base')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertNull(Action::query()->findOrFail($actionId)->synced_at);
        $this->assertSame('recELSEWHERE00001', DB::connection('portal')->table('requests')->where('id', $requestId)->value('airtable_record_id'));
    }

    public function test_a_send_goes_ahead_on_the_base_that_holds_them(): void
    {
        $this->fakeGone(true);
        $this->leaveRow('Pending', 'Vacation Leave', 'recSTILL00000001');

        $this->artisan('portal:airtable-sync', ['--send' => true])
            ->doesntExpectOutputToContain('names another base')
            ->assertExitCode(0);
    }

    public function test_a_second_send_waits_for_the_first(): void
    {
        $this->fakeAirtable();
        $lock = Cache::lock('portal:airtable-sync', 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('portal:airtable-sync', ['--send' => true])
                ->expectsOutputToContain('Another Airtable send is already running')
                ->assertExitCode(1);
        } finally {
            $lock->release();
        }
    }

    private function fakeAirtable(?array $schema = null): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/*' => Http::response($schema ?? $this->schema()),
            // `records` answers the base check's RECORD_ID() lookup: this base holds the database's rows.
            'api.airtable.com/v0/*' => Http::response(['id' => 'recNEW0000000001', 'fields' => [], 'records' => [['id' => 'recSTILL00000001']]]),
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
                ['name' => 'Category', 'type' => 'singleSelect', 'options' => $select(['Sick Leave', 'Vacation Leave', '1 Day'])],
                ['name' => 'Type', 'type' => 'singleLineText'],
                ['name' => 'Approver Remarks', 'type' => 'multilineText'],
                ['name' => 'No. of Hours', 'type' => 'number'],
                ['name' => 'Project', 'type' => 'multipleRecordLinks'],
            ],
        ], [
            'name' => 'Reimbursements',
            'fields' => [
                ['name' => 'Date', 'type' => 'date'],
                ['name' => 'Item', 'type' => 'singleLineText'],
                ['name' => 'Cost', 'type' => 'currency'],
                ['name' => 'QTY', 'type' => 'number'],
                ['name' => 'Purpose', 'type' => 'multilineText'],
                ['name' => 'Employee Name (Input your name so you can filter the IDs in "Reimburse To" field)', 'type' => 'singleLineText'],
                ['name' => 'Team', 'type' => 'singleSelect', 'options' => $select(['Angeles Pampanga Office', 'Cebu Office'])],
                ['name' => 'Status', 'type' => 'singleSelect', 'options' => $select(['Completed', 'Pending', 'Rejected'])],
                ['name' => 'Approver Remarks', 'type' => 'multilineText'],
                ['name' => 'Reimburse To', 'type' => 'multipleRecordLinks'],
                ['name' => 'Receipts (If Applicable)', 'type' => 'multipleAttachments'],
                ['name' => 'Reimbursement Receipt', 'type' => 'multipleAttachments'],
            ],
        ], [
            'name' => 'Employees',
            'fields' => [
                ['name' => 'Role', 'type' => 'singleSelect', 'options' => $select(['Admin', 'User', 'ProjectAdmin'])],
                ['name' => 'Email', 'type' => 'singleLineText'],
                ['name' => 'Nickname', 'type' => 'multilineText'],
                ['name' => 'ID No.', 'type' => 'singleLineText'],
                ['name' => 'First Name', 'type' => 'singleLineText'],
                ['name' => 'Last Name', 'type' => 'singleLineText'],
                ['name' => 'Job Title', 'type' => 'singleSelect', 'options' => $select(['BIM Modeler'])],
                ['name' => 'Status', 'type' => 'singleSelect', 'options' => $select(['Active', 'Inactive'])],
                ['name' => 'Department', 'type' => 'singleSelect', 'options' => $select(['MIS', 'Engineering'])],
            ],
        ], [
            'name' => 'Clients',
            'fields' => [
                ['name' => 'Name', 'type' => 'singleLineText'],
                ['name' => 'ClientID', 'type' => 'singleLineText'],
                ['name' => 'Notes', 'type' => 'multilineText'],
                ['name' => 'Status', 'type' => 'singleSelect', 'options' => $select(['Active'])],
            ],
        ], [
            'name' => 'User Reports',
            'fields' => [
                ['name' => 'Date', 'type' => 'date'],
                ['name' => 'Hours Rendered', 'type' => 'number'],
                ['name' => 'Change in Elements (+/-)', 'type' => 'number'],
                ['name' => 'Remarks', 'type' => 'singleLineText'],
                ['name' => 'Approval', 'type' => 'singleSelect', 'options' => $select(['Approved', 'Pending', 'Rejected'])],
                ['name' => 'LateSubmission', 'type' => 'singleSelect', 'options' => $select(['YES', 'NO'])],
                ['name' => 'LateSubmission Approval', 'type' => 'singleSelect', 'options' => $select(['Approved', 'Pending', 'Rejected', 'N/A'])],
                ['name' => 'Approver Remarks', 'type' => 'multilineText'],
                ['name' => 'Actual Work Date', 'type' => 'date'],
                ['name' => 'IsSupport', 'type' => 'singleSelect', 'options' => $select(['Y', 'N'])],
                ['name' => 'End date', 'type' => 'date'],
                ['name' => 'Duration', 'type' => 'number'],
                ['name' => 'Employee No.', 'type' => 'multipleRecordLinks'],
                ['name' => 'Project', 'type' => 'multipleRecordLinks'],
                ['name' => 'Activity', 'type' => 'multipleRecordLinks'],
                ['name' => 'Earn Code', 'type' => 'multipleRecordLinks'],
            ],
        ], [
            'name' => 'Project/s',
            'fields' => [
                ['name' => 'Project Name', 'type' => 'multilineText'],
                ['name' => 'Department', 'type' => 'singleSelect', 'options' => $select(['Engineering', 'MIS', 'Admin', 'General'])],
                ['name' => 'Type of Job', 'type' => 'singleSelect', 'options' => $select(['Scan-to-BIM', 'CAD-to-BIM'])],
                ['name' => 'Area (in S.F.)', 'type' => 'number'],
                ['name' => 'Status', 'type' => 'singleSelect', 'options' => $select(['Started', 'Done', 'Closed'])],
                ['name' => 'Project Lead Email', 'type' => 'singleLineText'],
                ['name' => 'LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'AR LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'ST LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'MD LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'EL LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'PL LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'FP LOD', 'type' => 'singleSelect', 'options' => $select(['N/A', 'LOD 300'])],
                ['name' => 'Forma Link', 'type' => 'multilineText'],
                ['name' => 'MSTeams Project Link', 'type' => 'url'],
                ['name' => 'MS Planner Link', 'type' => 'url'],
                ['name' => 'Panoramic Link', 'type' => 'url'],
                ['name' => 'MS Loop Link', 'type' => 'url'],
                ['name' => 'Google Drive Folder Path', 'type' => 'singleLineText'],
                ['name' => 'ProjectCreationStatus', 'type' => 'singleSelect', 'options' => $select(['Approved', 'Rejected', 'Pending'])],
                ['name' => 'ProjectCreationApprover', 'type' => 'singleLineText'],
                ['name' => 'Project Approver Email', 'type' => 'singleLineText'],
                ['name' => 'ProjectCreationApproverRemarks', 'type' => 'multilineText'],
                ['name' => 'PM ID', 'type' => 'multipleRecordLinks'],
                ['name' => 'Client', 'type' => 'multipleRecordLinks'],
                ['name' => 'Project Members', 'type' => 'multipleRecordLinks'],
                ['name' => 'Support Members', 'type' => 'multipleRecordLinks'],
                ['name' => 'Is the ACC project renamed with ACC latest project number?', 'type' => 'checkbox'],
                ['name' => 'Is the project ledger (MS Teams Planner) moved and updated to For Submission (completed status)?', 'type' => 'checkbox'],
                ['name' => 'Are the details in the project ledger (MS Teams Planner) updated?', 'type' => 'checkbox'],
                ['name' => 'Total NoE', 'type' => 'rollup'],
            ],
        ]]];
    }

    private function leaveRow(string $status, string $category, ?string $airtableId = null): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'airtable_record_id' => $airtableId,
            'request_date' => '2026-10-05',
            'name' => 'Sync Tester',
            'reason' => 'Family trip',
            'status' => $status,
            'type' => 'Leave',
            'category' => $category,
        ]);
    }

    /**
     * PATCH and DELETE answer 403 as Airtable does for a missing record; the RECORD_ID() lookup says
     * whether it is really gone. A create returns a fresh id.
     */
    private function fakeGone(bool $exists): void
    {
        Http::fake(function (Request $request) use ($exists) {
            if ($request->method() === 'GET') {
                return str_contains($request->url(), '/meta/')
                    ? Http::response($this->schema())
                    : Http::response(['records' => $exists ? [['id' => 'recSTILL00000001']] : []]);
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'recNEW0000000009']);
            }

            return Http::response(['error' => ['type' => 'INVALID_PERMISSIONS_OR_MODEL_NOT_FOUND', 'message' => 'Invalid permissions, or the requested model was not found.']], 403);
        });
    }

    /**
     * Each create gets its own id, since airtable_record_id is UNIQUE; from `failFrom` on, it fails.
     */
    private function numberedCreates(?int $failFrom = null): \Closure
    {
        $count = 0;

        return function (Request $request) use (&$count, $failFrom) {
            if ($request->method() !== 'POST') {
                return Http::response($this->schema());
            }
            $count++;

            return $failFrom !== null && $count >= $failFrom
                ? Http::response(['error' => ['type' => 'INVALID_REQUEST', 'message' => 'Nope']], 422)
                : Http::response(['id' => 'recLINE00000000'.$count]);
        };
    }

    private function reportLine(?string $airtableId = null): int
    {
        $portal = DB::connection('portal');
        $id = (int) $portal->table('user_reports')->insertGetId([
            'airtable_record_id' => $airtableId, 'report_date' => '2026-10-05', 'hours_rendered' => '4.00',
            'change_in_elements' => '1.00', 'approval' => 'Pending',
        ]);
        $employee = $portal->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->value('id');
        $portal->table('employees_user_reports')->insert(['employee_id' => $employee, 'user_report_id' => $id]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function reportAction(array $parameters, string $type = 'add'): int
    {
        $ledger = app(CoreLedger::class);
        $parameters = ['id' => '2026-10-05-daily', ...$parameters];

        return $type === 'add'
            ? $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-daily', 'portal.user_reports', $parameters, 'reports.submitted', '2026-10-05-daily', 1, null)
            : $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.user_reports', $parameters, 'reports.submitted', '2026-10-05-daily', '1:2026-10-05-daily', 1, null);
    }

    private function ledgerAdd(int $requestId, string $type = 'add', string $resource = 'requests.leave'): int
    {
        $ledger = app(CoreLedger::class);

        return $type === 'add'
            ? $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, '1:2026-10-05-leave', 'portal.requests', ['id' => (string) $requestId], $resource, (string) $requestId, 1, null)
            : $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $requestId], $resource, (string) $requestId, null, 1, null);
    }

    /**
     * A project row, carrying only what the portal's own editor writes.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function projectRow(array $overrides = []): int
    {
        return (int) DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => '900001',
            'project_name' => '900001 Sync Test',
            'department' => 'Engineering',
            'type_of_job' => 'Scan-to-BIM',
            'status' => 'Started',
            'project_creation_status' => 'Pending',
            ...$overrides,
        ]);
    }

    // One person on the project's junction, by the role that tells Airtable's two people fields apart.
    private function linkPerson(int $projectId, string $role, int $index): string
    {
        $employee = DB::connection('portal')->table('employees')->whereNotNull('airtable_record_id')->orderBy('id')->skip($index)->first();
        DB::connection('portal')->table('employees_projects')->insert([
            'employee_id' => $employee->id, 'project_id' => $projectId, 'role_on_project' => $role,
        ]);

        return (string) $employee->airtable_record_id;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function ledgerProject(int $projectId, string $type, array $parameters): int
    {
        $ledger = app(CoreLedger::class);
        $parameters = ['id' => (string) $projectId, ...$parameters];

        return $type === 'add'
            ? $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, '1:900001', 'portal.projects', $parameters, 'projects', (string) $projectId, 1, null)
            : $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.projects', $parameters, 'projects', (string) $projectId, '1:900001', 1, null);
    }
}
