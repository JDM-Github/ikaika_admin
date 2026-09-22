<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalAccessDenied;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalManageRequestsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
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

    public function test_a_member_cannot_read_the_company_queue(): void
    {
        $this->getJson('/api/development/portal/manage/requests', [
            'Authorization' => 'Bearer '.$this->tokenForMember(),
        ])
            ->assertForbidden()
            ->assertJsonPath('warning', PortalAccessDenied::WARNING_NOTIFIED);
    }

    public function test_the_queue_requires_a_bearer_token(): void
    {
        $this->getJson('/api/development/portal/manage/requests')->assertUnauthorized();
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

    private function tokenForAdmin(): string
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $this->loginToken($employee);
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
}
