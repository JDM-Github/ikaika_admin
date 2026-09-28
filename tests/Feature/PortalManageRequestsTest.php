<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Core\CoreActionType;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalLeaveRequests;
use App\Support\Portal\PortalRules;
use App\Support\Portal\PortalShellPath;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalManageRequestsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        // Envelope charging is PortalBudgetEnvelopesTest's; with the tracker off an approval needs no envelope.
        config(['products.catalog.transaction-tracker.enabled' => false]);
    }

    public function test_catalog_lists_manage_requests_under_portal_sections(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('sections.3.name', 'manage')
            ->assertJsonPath('sections.3.resources.1.name', 'requests')
            ->assertJsonPath('sections.3.resources.1.url', '/api/development/portal/manage/requests');
    }

    public function test_an_admin_reads_other_people_s_leave_on_the_queue(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $id = (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($member),
            'reason' => 'Family event',
            'status' => 'Pending',
            'type' => 'Leave',
            'category' => '01 Vacation Leave',
            'date_created' => $date.' 09:00:00',
        ]);
        $token = $this->tokenForAdmin();

        $response = $this->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJsonPath('section', 'manage')
            ->assertJsonPath('resource', 'requests');
        $ids = $response->json('data.*.id');
        $this->assertIsArray($ids);
        $this->assertContains((string) $id, $ids);
        $row = collect($response->json('data'))->firstWhere('id', (string) $id);
        $this->assertIsArray($row);
        $this->assertSame('leave', $row['kind']);
        $this->assertSame($this->memberName($member), $row['memberName']);
        $this->assertSame('pending', $row['status']);
        $this->assertArrayNotHasKey('bank_account_number', $row);
        $leave = collect($response->json('leave'))->firstWhere('id', (string) $id);
        $this->assertIsArray($leave);
        $this->assertSame($this->memberName($member), $leave['memberName']);
    }

    public function test_an_admin_saves_queue_status_and_approver_remarks(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $id = (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($member),
            'reason' => 'Family event',
            'status' => 'Pending',
            'type' => 'Leave',
            'category' => '01 Vacation Leave',
            'date_created' => $date.' 09:00:00',
        ]);
        $token = $this->tokenForAdmin();

        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => [[
                'id' => (string) $id,
                'kind' => 'leave',
                'status' => 'approved',
                'approverRemarks' => 'Coverage is in place.',
            ]],
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('saved', 1);

        $savedRow = DB::connection('portal')->table('requests')->where('id', $id)->first();
        $this->assertNotNull($savedRow);
        $this->assertSame('Approved', $savedRow->status);
        $this->assertSame('Coverage is in place.', $savedRow->approver_remarks);

        // The decision is a write the sync must push, so it lands in the ledger as an edit.
        $action = Action::query()
            ->where('resource', 'requests.leave')
            ->where('action_type', CoreActionType::EDIT)
            ->where('record_id', (string) $id)
            ->first();
        $this->assertNotNull($action);
        $this->assertSame('portal.requests', $action->database_target);
        $this->assertEquals([
            'id' => (string) $id,
            'requestedFor' => $date,
            'status' => 'Approved',
            'approverRemarks' => 'Coverage is in place.',
        ], $action->parameters['parameters']);

        $listed = $this->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date, [
            'Authorization' => "Bearer {$token}",
        ])->json('data');
        $this->assertIsArray($listed);
        $saved = collect($listed)->firstWhere('id', (string) $id);
        $this->assertIsArray($saved);
        $this->assertSame('approved', $saved['status']);
        $this->assertSame('Coverage is in place.', $saved['approverRemarks']);

        $log = PortalLog::query()
            ->where('resource', 'manage.requests')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame([[
            'id' => (string) $id,
            'kind' => 'leave',
            'status' => 'approved',
            'approverRemarks' => 'Coverage is in place.',
        ]], $log->payload['changes']);
    }

    /*
     * The decide-unit is the whole filing batch, not one row: two entries filed together
     * (PortalSubmittedReports::create() flags every line of one submission alike) must move
     * together, and is_flag itself -- the permanent "this was filed on a warning" fact -- must
     * survive the decision untouched.
     */
    public function test_an_admin_approves_a_flagged_report_with_remarks_and_the_employee_is_notified(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $firstId = $this->insertFlaggedLine($member, $date);
        $secondId = $this->insertFlaggedLine($member, $date);
        $token = $this->tokenForAdmin();

        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => [[
                'id' => (string) $firstId,
                'kind' => 'flag',
                'status' => 'approved',
                'approverRemarks' => 'Reviewed, looks fine.',
            ]],
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('saved', 1);

        foreach ([$firstId, $secondId] as $id) {
            $row = DB::connection('portal')->table('employees_user_reports')
                ->where('user_report_id', $id)->first();
            $this->assertNotNull($row);
            $this->assertSame(1, (int) $row->is_flag);
            $this->assertSame('Approved', $row->flag_status);
            $this->assertSame('Reviewed, looks fine.', $row->flag_remarks);
        }

        $action = Action::query()
            ->where('resource', 'reports.flagged')
            ->where('record_id', (string) $firstId)
            ->first();
        $this->assertNotNull($action);
        $this->assertSame('portal.user_reports', $action->database_target);
        $parameters = $action->parameters['parameters'];
        $this->assertSame('Approved', $parameters['flagStatus']);
        $this->assertContains($firstId, $parameters['userReportIds']);
        $this->assertContains($secondId, $parameters['userReportIds']);

        $notification = PortalNotification::query()
            ->where('employee_id', $member->getKey())
            ->where('type', 'reports.flagged.approved')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('flagged report', (string) $notification->message);

        $log = PortalLog::query()
            ->where('employee_id', $member->getKey())
            ->where('resource', 'reports.flagged')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame('flag', $log->payload['kind']);
        $this->assertSame('approved', $log->payload['status']);
    }

    // Rejecting keeps it on the list -- only approving is supposed to clear a flagged report.
    public function test_an_admin_rejects_a_flagged_report(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $id = $this->insertFlaggedLine($member, $date);

        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => [[
                'id' => (string) $id,
                'kind' => 'flag',
                'status' => 'rejected',
                'approverRemarks' => 'Needs a closer look.',
            ]],
        ], [
            'Authorization' => 'Bearer '.$this->tokenForAdmin(),
        ])->assertOk()->assertJsonPath('saved', 1);

        $row = DB::connection('portal')->table('employees_user_reports')
            ->where('user_report_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Rejected', $row->flag_status);
    }

    // The approver reads everybody's flagged reports, so unlike GET /reports/flagged (the
    // member's own, name always null) this bundle names whose report each one is.
    public function test_get_manage_requests_bundles_flagged_reports_with_real_names(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $id = $this->insertFlaggedLine($member, $date);
        $unflagged = $this->insertFlaggedLine($member, $date, isFlag: false);

        $response = $this->getJson(
            '/api/development/portal/manage/requests?from='.$date.'&to='.$date,
            ['Authorization' => 'Bearer '.$this->tokenForAdmin()],
        )->assertOk();

        $flaggedIds = collect($response->json('flaggedReports'))->pluck('id');
        $this->assertContains((string) $id, $flaggedIds);
        $this->assertNotContains((string) $unflagged, $flaggedIds);
        $row = collect($response->json('flaggedReports'))->firstWhere('id', (string) $id);
        $this->assertIsArray($row);
        $this->assertSame($this->memberName($member), $row['memberName']);
        $this->assertSame('pending', $row['status']);
    }

    // A report that was never flagged has nothing here to decide, the same way an id belonging
    // to no reimbursement or no queued request is refused.
    public function test_deciding_a_report_that_was_never_flagged_is_refused(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $id = $this->insertFlaggedLine($member, $date, isFlag: false);

        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => [[
                'id' => (string) $id,
                'kind' => 'flag',
                'status' => 'approved',
            ]],
        ], [
            'Authorization' => 'Bearer '.$this->tokenForAdmin(),
        ])->assertStatus(404);
    }

    public function test_each_owner_gets_an_inbox_row_when_their_request_is_reviewed(): void
    {
        $owners = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereNotNull('first_name')
            ->orderBy('id')
            ->limit(3)
            ->get();
        $this->assertCount(3, $owners);
        $this->assertCount(3, $owners->pluck('id')->unique());

        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $changes = [];
        foreach ($owners as $owner) {
            $id = (int) DB::connection('portal')->table('requests')->insertGetId([
                'request_date' => $date,
                'name' => $this->memberName($owner),
                'reason' => 'Coverage check',
                'status' => 'Pending',
                'type' => 'Leave',
                'category' => '01 Vacation Leave',
                'date_created' => $date.' 09:00:00',
            ]);
            $changes[] = [
                'id' => (string) $id,
                'kind' => 'leave',
                'status' => 'approved',
                'approverRemarks' => 'Noted.',
            ];
        }

        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => $changes,
        ], [
            'Authorization' => 'Bearer '.$this->tokenForAdmin(),
        ])->assertOk()
            ->assertJsonPath('saved', 3);

        foreach ($owners as $owner) {
            $row = PortalNotification::query()
                ->where('employee_id', $owner->getKey())
                ->where('type', 'requests.leave.approved')
                ->orderByDesc('id')
                ->first();
            $this->assertNotNull($row);
            $this->assertSame('/requests/user-requests', $row->link_path);
            $this->assertStringContainsString('was approved by', (string) $row->message);

            $log = PortalLog::query()
                ->where('employee_id', $owner->getKey())
                ->where('resource', 'requests.leave')
                ->orderByDesc('id')
                ->first();
            $this->assertNotNull($log);
            $this->assertIsArray($log->payload);
            $this->assertSame('leave', $log->payload['kind']);
            $this->assertSame($date, $log->payload['requestedFor']);
            $this->assertSame('approved', $log->payload['status']);
        }
    }

    public function test_a_member_cannot_save_queue_decisions(): void
    {
        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => [[
                'id' => '1',
                'kind' => 'leave',
                'status' => 'approved',
            ]],
        ], [
            'Authorization' => 'Bearer '.$this->tokenForMember(),
        ])
            ->assertForbidden();
    }

    // The page follows its rule: a plain refusal, not a security alert, since switching it off yourself lands here too.
    public function test_a_member_cannot_read_the_company_queue(): void
    {
        $this->getJson('/api/development/portal/manage/requests', [
            'Authorization' => 'Bearer '.$this->tokenForMember(),
        ])
            ->assertForbidden()
            ->assertJsonPath('message', PortalRules::REFUSAL)
            ->assertJsonMissingPath('warning');
    }

    public function test_the_queue_requires_a_bearer_token(): void
    {
        $this->getJson('/api/development/portal/manage/requests')->assertUnauthorized();
    }

    public function test_an_admin_attaches_a_payout_receipt_to_an_approved_claim(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/auto/upload' => Http::response([
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/reimbursements/payout.jpg',
                'original_filename' => 'payout',
                'bytes' => 900,
            ], 200),
        ]);

        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Grab to the site',
            'cost' => 250.0,
            'qty' => 1,
            'purpose' => 'Site visit',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Angeles Pampanga Office',
            'status' => 'Approved',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $member->getKey(),
            'reimbursement_id' => $itemId,
        ]);
        $token = $this->tokenForAdmin();
        $file = UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg');

        $uploaded = $this->withToken($token)->post(
            "/api/development/portal/manage/requests/reimbursement/{$itemId}/payout-receipt",
            ['file' => $file],
        )->assertCreated();

        $this->assertSame('payout.jpg', $uploaded->json('data.payoutReceiptName'));
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/reimbursements/payout.jpg',
            $uploaded->json('data.payoutReceiptUrl'),
        );

        $response = $this->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date, [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();
        $claim = collect($response->json('reimbursements'))
            ->first(fn (array $claim): bool => collect($claim['items'])->contains('id', (string) $itemId));
        $this->assertIsArray($claim);
        $item = collect($claim['items'])->firstWhere('id', (string) $itemId);
        $this->assertIsArray($item);
        $this->assertSame('payout.jpg', $item['payoutReceiptName']);
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/reimbursements/payout.jpg',
            $item['payoutReceiptUrl'],
        );
        $this->assertTrue(DB::connection('portal')->table('attachments')
            ->where('table_name', 'reimbursements')
            ->where('record_id', $itemId)
            ->where('field_name', 'payout_receipt')
            ->exists());

        $action = Action::query()
            ->where('resource', 'requests.reimbursement')
            ->where('action_type', CoreActionType::EDIT)
            ->where('record_id', (string) $itemId)
            ->first();
        $this->assertNotNull($action);
        $this->assertSame('payout.jpg', $action->parameters['parameters']['payoutReceipt']['fileName']);
    }

    // A member's write must show on the admin queue at once, not after the queue's cache expires.
    public function test_a_write_elsewhere_in_the_portal_refreshes_the_cached_queue(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $token = $this->tokenForAdmin();
        $url = '/api/development/portal/manage/requests?from='.$date.'&to='.$date;

        $this->getJson($url, ['Authorization' => "Bearer {$token}"])->assertOk();

        $id = (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($member),
            'reason' => 'Filed after the queue was cached',
            'status' => 'Pending',
            'type' => 'Leave',
            'category' => '01 Vacation Leave',
            'date_created' => $date.' 09:00:00',
        ]);
        // What the leave form does after its write; it used to clear only its own cache.
        app(PortalLeaveRequests::class)->bumpCache();

        $listed = $this->getJson($url, ['Authorization' => "Bearer {$token}"])->json('data');
        $this->assertIsArray($listed);
        $this->assertNotNull(collect($listed)->firstWhere('id', (string) $id));
    }

    // update() counts changed rows, so re-sending the same decision used to read as a missing row.
    public function test_repeating_a_reimbursement_decision_is_not_refused(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Printer ink',
            'cost' => 120.0,
            'qty' => 1,
            'purpose' => 'Plans',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Cebu Office',
            'status' => 'Approved',
            'date_created' => $date.' 09:00:00',
        ]);
        $token = $this->tokenForAdmin();
        $change = ['changes' => [[
            'id' => (string) $itemId,
            'kind' => 'reimbursement',
            'status' => 'approved',
            'approverRemarks' => null,
        ]]];

        $this->patchJson('/api/development/portal/manage/requests', $change, [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('saved', 1);

        $this->assertSame(1, Action::query()
            ->where('resource', 'requests.reimbursement')
            ->where('record_id', (string) $itemId)
            ->count());
    }

    public function test_a_payout_receipt_is_refused_on_a_claim_that_is_not_approved_yet(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Grab to the site',
            'cost' => 250.0,
            'qty' => 1,
            'purpose' => 'Site visit',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Angeles Pampanga Office',
            'status' => 'Pending',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $member->getKey(),
            'reimbursement_id' => $itemId,
        ]);
        $token = $this->tokenForAdmin();
        $file = UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg');

        $this->withToken($token)->post(
            "/api/development/portal/manage/requests/reimbursement/{$itemId}/payout-receipt",
            ['file' => $file],
        )->assertStatus(422);
    }

    public function test_a_payout_receipt_is_refused_for_a_reimbursement_that_does_not_exist(): void
    {
        $token = $this->tokenForAdmin();
        $file = UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg');

        $this->withToken($token)->post(
            '/api/development/portal/manage/requests/reimbursement/999999/payout-receipt',
            ['file' => $file],
        )->assertStatus(404);
    }

    public function test_a_member_cannot_attach_a_payout_receipt(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Grab to the site',
            'cost' => 250.0,
            'qty' => 1,
            'purpose' => 'Site visit',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Angeles Pampanga Office',
            'status' => 'Approved',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $member->getKey(),
            'reimbursement_id' => $itemId,
        ]);
        $token = $this->tokenForMember();
        $file = UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg');

        $this->withToken($token)->post(
            "/api/development/portal/manage/requests/reimbursement/{$itemId}/payout-receipt",
            ['file' => $file],
        )->assertForbidden();
    }

    public function test_an_admin_sees_the_receipt_on_a_reimbursement_claim(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Grab to the site',
            'cost' => 250.0,
            'qty' => 1,
            'purpose' => 'Site visit',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Angeles Pampanga Office',
            'status' => 'Pending',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $member->getKey(),
            'reimbursement_id' => $itemId,
        ]);
        DB::connection('portal')->table('attachments')->insert([
            'table_name' => 'reimbursements',
            'field_name' => 'receipts',
            'record_id' => $itemId,
            'file_url' => 'https://res.cloudinary.com/ikaika/image/upload/v1/receipts/grab.jpg',
            'file_name' => 'grab.jpg',
            'file_size_bytes' => 12345,
            'mime_type' => 'image/jpeg',
        ]);
        $token = $this->tokenForAdmin();

        $response = $this->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date, [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $claim = collect($response->json('reimbursements'))
            ->first(fn (array $claim): bool => collect($claim['items'])->contains('id', (string) $itemId));
        $this->assertIsArray($claim);
        $item = collect($claim['items'])->firstWhere('id', (string) $itemId);
        $this->assertIsArray($item);
        $this->assertSame('grab.jpg', $item['receiptName']);
        $this->assertSame('https://res.cloudinary.com/ikaika/image/upload/v1/receipts/grab.jpg', $item['receiptUrl']);
        $this->assertSame('image/jpeg', $item['receiptMime']);
        $this->assertSame(
            'https://res.cloudinary.com/ikaika/image/upload/c_fill,h_96,w_96,f_auto,q_auto/v1/receipts/grab.jpg',
            $item['receiptThumbUrl'],
        );
    }

    public function test_a_reimbursement_claim_with_no_attachment_reads_null_receipt_fields(): void
    {
        $member = $this->activeMember();
        $date = Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Parking',
            'cost' => 50.0,
            'qty' => 1,
            'purpose' => 'Site visit',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Angeles Pampanga Office',
            'status' => 'Pending',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $member->getKey(),
            'reimbursement_id' => $itemId,
        ]);
        $token = $this->tokenForAdmin();

        $response = $this->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date, [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $claim = collect($response->json('reimbursements'))
            ->first(fn (array $claim): bool => collect($claim['items'])->contains('id', (string) $itemId));
        $item = collect($claim['items'])->firstWhere('id', (string) $itemId);
        $this->assertIsArray($item);
        $this->assertNull($item['receiptName']);
        $this->assertNull($item['receiptUrl']);
        $this->assertNull($item['receiptMime']);
        $this->assertNull($item['receiptThumbUrl']);
    }

    public function test_a_department_admin_sees_only_their_own_department_on_the_queue(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $theirs = $this->insertLeave($this->memberName($outside), $date);
        $nobody = $this->insertLeave('Nobody Onstaff', $date);

        $ids = $this->queuedLeaveIds($this->loginToken($this->departmentAdmin()), $date);

        $this->assertContains((string) $mine, $ids);
        $this->assertNotContains((string) $theirs, $ids);
        $this->assertNotContains((string) $nobody, $ids);
    }

    public function test_a_developer_sees_every_department_and_unmatched_rows(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $expected = [
            (string) $this->insertLeave($this->memberName($inside), $date),
            (string) $this->insertLeave($this->memberName($outside), $date),
            (string) $this->insertLeave('Nobody Onstaff', $date),
        ];

        $ids = $this->queuedLeaveIds($this->developerModeOn($this->loginToken($this->departmentAdmin(developer: true))), $date);

        foreach ($expected as $id) {
            $this->assertContains($id, $ids);
        }
    }

    // Executives are managed from their own portal, so their rows are on nobody's queue here.
    public function test_an_executive_s_request_is_on_nobody_s_queue(): void
    {
        $executive = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->first();
        $this->assertNotNull($executive);
        $date = $this->today();
        $theirs = $this->insertLeave($this->memberName($executive), $date);
        $token = $this->tokenForAdmin();

        $this->assertNotContains((string) $theirs, $this->queuedLeaveIds($token, $date));
        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $theirs, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => '']],
        ])->assertNotFound();
    }

    // Executives start with every department, so their queue is the whole company's.
    public function test_an_executive_sees_every_department(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $theirs = $this->insertLeave($this->memberName($outside), $date);

        $ids = $this->queuedLeaveIds($this->loginToken($this->executive()), $date);

        $this->assertContains((string) $mine, $ids);
        $this->assertContains((string) $theirs, $ids);
    }

    // Each row names its owner's department, which the page filters on.
    public function test_each_row_names_its_owner_s_department(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $theirs = $this->insertLeave($this->memberName($outside), $date);
        $claim = $this->insertApprovedClaim($outside, $date);
        $flag = $this->insertFlaggedLine($inside, $date);

        $response = $this->withToken($this->tokenForAdmin())
            ->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date)
            ->assertOk();

        $this->assertSame('mis', collect($response->json('leave'))->firstWhere('id', (string) $mine)['department']);
        $this->assertSame('Engineering', collect($response->json('leave'))->firstWhere('id', (string) $theirs)['department']);
        $this->assertSame('Engineering', collect($response->json('data'))->firstWhere('id', (string) $theirs)['department']);
        $this->assertSame('Engineering', collect($response->json('reimbursements'))->firstWhere('id', (string) $claim)['department']);
        $this->assertSame('mis', collect($response->json('flaggedReports'))->firstWhere('id', (string) $flag)['department']);
    }

    // Each kind follows its own rule: someone given only Leave Requests works the leave queue and nothing else.
    public function test_a_member_given_leave_requests_works_only_that_queue(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $member = $this->misMember($inside, $outside);
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $theirs = $this->insertLeave($this->memberName($outside), $date);
        $claim = $this->insertApprovedClaim($inside, $date);
        $this->insertFlaggedLine($inside, $date);
        $this->withToken($this->loginToken($this->departmentAdmin()))
            ->patchJson("/api/development/portal/manage/access/{$member->id}", ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])
            ->assertOk();
        $token = $this->loginToken($member);

        $response = $this->withToken($token)->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date)->assertOk();

        $this->assertContains((string) $mine, $response->json('leave.*.id'));
        $this->assertNotContains((string) $theirs, $response->json('leave.*.id'));
        $this->assertContains((string) $mine, $response->json('data.*.id'));
        $this->assertSame([], $response->json('reimbursements'));
        $this->assertSame([], $response->json('flaggedReports'));
        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $mine, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => '']],
        ])->assertOk()->assertJsonPath('saved', 1);
        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $claim, 'kind' => 'reimbursement', 'status' => 'rejected', 'approverRemarks' => '']],
        ])->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject reimbursement requests.');
        $this->assertSame('Approved', DB::connection('portal')->table('reimbursements')->where('id', $claim)->value('status'));
    }

    // Late reports follow manage.requests.late like every other kind: its reach, and its own Approve or reject.
    public function test_late_reports_follow_their_own_rule_and_reach(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLateLine($inside, $date);
        $theirs = $this->insertLateLine($outside, $date);
        $token = $this->loginToken($this->departmentAdmin());
        $decide = fn (Employee $member, string $status): mixed => $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => $member->id.':'.$date, 'kind' => 'late', 'status' => $status, 'approverRemarks' => 'Checked.']],
        ]);

        $ids = $this->withToken($token)->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date)->assertOk()->json('lateReports.*.id');
        $this->assertContains($inside->id.':'.$date, $ids);
        $this->assertNotContains($outside->id.':'.$date, $ids);

        $decide($outside, 'approved')->assertNotFound()->assertJsonPath('message', 'That late report is not on the queue.');
        $this->assertSame('Pending', DB::connection('portal')->table('user_reports')->where('id', $theirs)->value('late_submission_approval'));

        $decide($inside, 'approved')->assertOk()->assertJsonPath('saved', 1);
        $this->assertSame('Approved', DB::connection('portal')->table('user_reports')->where('id', $mine)->value('late_submission_approval'));
        // Ledgered apart from reports.submitted, which the sync sends as the filing itself.
        $action = Action::query()->where('resource', 'reports.late')->where('record_id', $inside->id.':'.$date)->first();
        $this->assertNotNull($action);
        $this->assertSame('portal.user_reports', $action->database_target);
        $this->assertSame([$mine], $action->parameters['parameters']['userReportIds']);

        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'manage.requests.late.decide', 'on' => false])->assertOk();
        $decide($inside, 'rejected')->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject late reports.');
        $this->assertSame('Approved', DB::connection('portal')->table('user_reports')->where('id', $mine)->value('late_submission_approval'));
    }

    // Switching a kind off takes its list away and every decision on it with it.
    public function test_switching_a_kind_off_takes_its_list_away(): void
    {
        [$inside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $flag = $this->insertFlaggedLine($inside, $date);
        $token = $this->loginToken($this->departmentAdmin());
        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'manage.requests.leave', 'on' => false])->assertOk();

        $response = $this->withToken($token)->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date)->assertOk();

        $this->assertNotContains((string) $mine, $response->json('leave.*.id'));
        $this->assertNotContains((string) $mine, $response->json('data.*.id'));
        $this->assertContains((string) $flag, $response->json('flaggedReports.*.id'));
        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $mine, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => '']],
        ])->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject leave requests.');
    }

    // Without Approve or reject the queue stays readable, and a batch touching that kind writes nothing at all.
    public function test_without_approve_or_reject_a_kind_is_read_only(): void
    {
        [$inside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $flag = $this->insertFlaggedLine($inside, $date);
        $token = $this->loginToken($this->departmentAdmin());
        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'manage.requests.leave.decide', 'on' => false])->assertOk();

        $this->assertContains((string) $mine, $this->queuedLeaveIds($token, $date));
        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [
                ['id' => (string) $flag, 'kind' => 'flag', 'status' => 'approved', 'approverRemarks' => ''],
                ['id' => (string) $mine, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => ''],
            ],
        ])->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject leave requests.');

        $this->assertSame('Pending', DB::connection('portal')->table('requests')->where('id', $mine)->value('status'));
        $this->assertSame('Pending', DB::connection('portal')->table('employees_user_reports')->where('user_report_id', $flag)->value('flag_status'));
    }

    // Narrowed to own, a kind lists only the person's own requests, and deciding anyone else's is refused.
    public function test_an_own_reach_lists_only_their_own_requests(): void
    {
        [$inside] = $this->membersInBothDepartments();
        $admin = $this->departmentAdmin();
        $date = $this->today();
        $own = $this->insertLeave($this->memberName($admin), $date);
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $token = $this->loginToken($admin);
        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'manage.requests.leave', 'reach' => 'own'])->assertOk();

        $ids = $this->queuedLeaveIds($token, $date);

        $this->assertContains((string) $own, $ids);
        $this->assertNotContains((string) $mine, $ids);
        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $mine, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => '']],
        ])->assertNotFound()->assertJsonPath('message', 'That request is not on the queue.');
    }

    public function test_payout_receipts_follow_their_own_rule(): void
    {
        Http::preventStrayRequests();
        [$inside] = $this->membersInBothDepartments();
        $claim = $this->insertApprovedClaim($inside, $this->today());
        $token = $this->loginToken($this->departmentAdmin());
        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'manage.requests.reimbursement.payout', 'on' => false])->assertOk();

        $this->withToken($token)->post(
            "/api/development/portal/manage/requests/reimbursement/{$claim}/payout-receipt",
            ['file' => UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg')],
            ['Accept' => 'application/json'],
        )->assertForbidden()->assertJsonPath('message', PortalRules::REFUSAL);
    }

    // Request alerts reach whoever holds them and whose rule for that kind covers the person filing.
    public function test_request_alerts_follow_their_rule_and_its_reach(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $admin = $this->departmentAdmin();
        $executive = $this->executive();
        $alert = function (Employee $filer): void {
            app(PortalAudit::class)->notifyManagers($filer, 'manage.requests.leave', 'requests.leave.filed', 'New leave request', 'Filed', PortalShellPath::MANAGE_REQUESTS);
        };
        $inbox = static fn (Employee $recipient, Employee $filer): int => PortalNotification::query()
            ->where('employee_id', $recipient->id)
            ->where('actor_id', $filer->id)
            ->count();

        $alert($inside);
        $alert($outside);

        $this->assertSame(1, $inbox($admin, $inside));
        $this->assertSame(0, $inbox($admin, $outside));
        $this->assertSame(1, $inbox($executive, $outside));
        $this->assertSame(0, $inbox($outside, $inside));

        // Switched off, or without the kind itself, the admin hears nothing even from their own department.
        foreach ([PortalAudit::ALERTS_RULE, 'manage.requests.leave'] as $key) {
            DB::connection('portal')->table('access_toggles')->delete();
            DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $admin->id, 'permission_key' => $key]);
            $alert($inside);
            $this->assertSame(1, $inbox($admin, $inside));
        }
    }

    // One department's cached queue must never be served to another department's admin.
    public function test_the_cached_queue_is_kept_apart_per_department(): void
    {
        [, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $theirs = $this->insertLeave($this->memberName($outside), $date);
        $this->assertContains((string) $theirs, $this->queuedLeaveIds($this->tokenForAdmin(), $date));

        $ids = $this->queuedLeaveIds($this->loginToken($this->departmentAdmin()), $date);

        $this->assertNotContains((string) $theirs, $ids);
    }

    public function test_a_department_admin_cannot_decide_another_department_s_request(): void
    {
        [, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $theirs = $this->insertLeave($this->memberName($outside), $date);
        $nobody = $this->insertLeave('Nobody Onstaff', $date);
        $token = $this->loginToken($this->departmentAdmin());

        foreach ([$theirs, $nobody] as $id) {
            $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
                'changes' => [['id' => (string) $id, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => '']],
            ])->assertNotFound()->assertJsonPath('message', 'That request is not on the queue.');

            $this->assertSame('Pending', DB::connection('portal')->table('requests')->where('id', $id)->value('status'));
            $this->assertFalse(Action::query()->where('resource', 'requests.leave')->where('record_id', (string) $id)->exists());
        }
    }

    public function test_a_department_admin_cannot_decide_another_department_s_claim_or_flag(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $claim = $this->insertApprovedClaim($outside, $date);
        $flag = $this->insertFlaggedLine($outside, $date);
        $ownFlag = $this->insertFlaggedLine($inside, $date);
        $token = $this->loginToken($this->departmentAdmin());

        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $claim, 'kind' => 'reimbursement', 'status' => 'rejected', 'approverRemarks' => '']],
        ])->assertNotFound()->assertJsonPath('message', 'That reimbursement is not on the queue.');
        $this->assertSame('Approved', DB::connection('portal')->table('reimbursements')->where('id', $claim)->value('status'));

        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $flag, 'kind' => 'flag', 'status' => 'approved', 'approverRemarks' => '']],
        ])->assertNotFound()->assertJsonPath('message', 'That flagged report is not on the queue.');
        $this->assertSame('Pending', DB::connection('portal')->table('employees_user_reports')->where('user_report_id', $flag)->value('flag_status'));

        $this->withToken($token)->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => (string) $ownFlag, 'kind' => 'flag', 'status' => 'approved', 'approverRemarks' => '']],
        ])->assertOk()->assertJsonPath('saved', 1);
    }

    public function test_a_department_admin_cannot_attach_a_payout_receipt_to_another_department_s_claim(): void
    {
        Http::preventStrayRequests();
        [, $outside] = $this->membersInBothDepartments();
        $claim = $this->insertApprovedClaim($outside, $this->today());
        $file = UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg');

        $this->withToken($this->loginToken($this->departmentAdmin()))->post(
            "/api/development/portal/manage/requests/reimbursement/{$claim}/payout-receipt",
            ['file' => $file],
            ['Accept' => 'application/json'],
        )->assertNotFound()->assertJsonPath('message', 'That reimbursement is not on the queue.');
    }

    // In Developer mode a developer can do everything an admin can, even with a member role.
    public function test_a_member_role_developer_reads_the_queue_in_developer_mode(): void
    {
        $member = $this->activeMember();
        $member->forceFill(['is_developer' => true])->save();

        $this->withToken($this->developerModeOn($this->loginToken($member)))
            ->getJson('/api/development/portal/manage/requests')
            ->assertOk();
    }

    // In Default a developer is exactly a department admin, decisions included.
    public function test_a_developer_in_default_is_held_to_their_department(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $mine = $this->insertLeave($this->memberName($inside), $date);
        $theirs = $this->insertLeave($this->memberName($outside), $date);
        $token = $this->loginToken($this->departmentAdmin(developer: true));

        $ids = $this->queuedLeaveIds($token, $date);

        $this->assertContains((string) $mine, $ids);
        $this->assertNotContains((string) $theirs, $ids);
        $this->withToken($token)
            ->patchJson('/api/development/portal/manage/requests', [
                'changes' => [['id' => (string) $theirs, 'kind' => 'leave', 'status' => 'approved', 'approverRemarks' => '']],
            ])->assertNotFound();
        $this->assertSame('Pending', DB::connection('portal')->table('requests')->where('id', $theirs)->value('status'));
        $this->assertContains((string) $theirs, $this->queuedLeaveIds($this->developerModeOn($token), $date));
    }

    public function test_a_member_role_developer_in_default_is_refused_the_queue(): void
    {
        $member = $this->activeMember();
        $member->forceFill(['is_developer' => true])->save();

        $this->withToken($this->loginToken($member))
            ->getJson('/api/development/portal/manage/requests')
            ->assertForbidden();
    }

    // The retired preview header grants nothing, even asking for developer.
    public function test_the_old_preview_header_grants_nothing(): void
    {
        [, $outside] = $this->membersInBothDepartments();
        $date = $this->today();
        $theirs = $this->insertLeave($this->memberName($outside), $date);

        $ids = $this->queuedLeaveIds($this->loginToken($this->departmentAdmin(developer: true)), $date, ['X-Portal-View' => 'developer']);

        $this->assertNotContains((string) $theirs, $ids);
    }

    // A developer sees every department, so tests about the queue itself do not depend on who is where.
    private function tokenForAdmin(): string
    {
        return $this->developerModeOn($this->loginToken($this->departmentAdmin(developer: true)));
    }

    private function departmentAdmin(bool $developer = false): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);
        $employee->forceFill(['is_developer' => $developer, 'department' => 'MIS'])->save();

        return $employee;
    }

    /**
     * Two active members, one in MIS written loosely and one in Engineering.
     *
     * @return array{0: Employee, 1: Employee}
     */
    private function membersInBothDepartments(): array
    {
        $members = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('first_name')
            ->where('is_developer', false)
            ->limit(2)
            ->get();
        $this->assertCount(2, $members);
        $inside = $members[0];
        $outside = $members[1];
        $inside->forceFill(['department' => ' mis '])->save();
        $outside->forceFill(['department' => 'Engineering'])->save();

        return [$inside, $outside];
    }

    private function today(): string
    {
        return Carbon::now($this->app['config']->get('app.timezone'))->toDateString();
    }

    private function insertLeave(string $name, string $date): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $name,
            'reason' => 'Family event',
            'status' => 'Pending',
            'type' => 'Leave',
            'category' => '01 Vacation Leave',
            'date_created' => $date.' 09:00:00',
        ]);
    }

    private function insertApprovedClaim(Employee $member, string $date): int
    {
        $itemId = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => 'Grab to the site',
            'cost' => 250.0,
            'qty' => 1,
            'purpose' => 'Site visit',
            'employee_name_input' => $this->memberName($member),
            'team' => 'Angeles Pampanga Office',
            'status' => 'Approved',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $member->getKey(),
            'reimbursement_id' => $itemId,
        ]);

        return $itemId;
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function queuedLeaveIds(string $token, string $date, array $headers = []): array
    {
        $ids = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/development/portal/manage/requests?from='.$date.'&to='.$date)
            ->assertOk()
            ->json('leave.*.id');
        $this->assertIsArray($ids);

        return $ids;
    }

    private function executive(): Employee
    {
        $executive = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($executive);
        $executive->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        return $executive;
    }

    // One more active member in MIS, never one of the people given.
    private function misMember(Employee ...$except): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereNotNull('first_name')
            ->whereNotIn('id', array_map(static fn (Employee $person): int => (int) $person->getKey(), $except))
            ->first();
        $this->assertNotNull($employee);
        $employee->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        return $employee;
    }

    private function tokenForMember(): string
    {
        return $this->loginToken($this->activeMember());
    }

    private function loginToken(Employee $employee): string
    {
        $this->assertNotEmpty($employee->id_no);
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereNotNull('first_name')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function memberName(Employee $actor): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
    }

    private function insertLateLine(Employee $member, string $date): int
    {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $date,
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'remarks' => 'Filed late',
            'late_submission' => 'Yes',
            'late_submission_approval' => 'Pending',
            'approval' => 'Approved',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $member->getKey(),
            'user_report_id' => $id,
        ]);

        return $id;
    }

    private function insertFlaggedLine(Employee $member, string $date, bool $isFlag = true): int
    {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $date,
            'hours_rendered' => 8,
            'change_in_elements' => 1,
            'remarks' => 'Test entry',
            'approval' => 'Approved',
            'date_created' => $date.' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $member->getKey(),
            'user_report_id' => $id,
            'is_flag' => $isFlag,
            'flag_status' => 'Pending',
        ]);

        return $id;
    }
}
