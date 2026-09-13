<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalLogAction;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalAllLogsTest extends TestCase
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

    public function test_catalog_lists_all_logs_under_administration(): void
    {
        $sections = $this->getJson('/api/development/portal')
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);
        $administration = collect($sections)->firstWhere('name', 'administration');
        $this->assertIsArray($administration);
        $resource = collect($administration['resources'] ?? [])->firstWhere('name', 'all-logs');
        $this->assertIsArray($resource);
        $this->assertSame('All Logs', $resource['label'] ?? null);
        $this->assertTrue($resource['requires_admin'] ?? false);
        $this->assertSame(
            '/api/development/portal/administration/all-logs',
            $resource['url'] ?? null,
        );
    }

    public function test_a_guest_cannot_read_all_logs(): void
    {
        $this->getJson('/api/development/portal/administration/all-logs')
            ->assertUnauthorized();
    }

    public function test_a_member_cannot_read_all_logs(): void
    {
        $member = $this->activeMember();
        $token = $this->loginToken($member);

        $this->withToken($token)
            ->getJson('/api/development/portal/administration/all-logs')
            ->assertForbidden();
    }

    public function test_an_admin_reads_every_members_logs_and_available_users(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $this->assertNotSame((int) $admin->getKey(), (int) $member->getKey());
        $token = $this->loginToken($admin);
        $audit = app(PortalAudit::class);

        $audit->record(
            $member,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added a daily report',
            'all-logs-member',
            Request::create(
                '/api/development/portal/reports/submitted',
                'POST',
                [],
                [],
                [],
                ['REMOTE_ADDR' => '203.0.113.40'],
            ),
        );

        $response = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/administration/all-logs?per_page=100')
            ->assertOk()
            ->assertJsonPath('section', 'administration')
            ->assertJsonPath('resource', 'all-logs');

        $ids = array_column($response->json('data'), 'recordId');
        $this->assertContains('all-logs-member', $ids);

        $row = collect($response->json('data'))->firstWhere('recordId', 'all-logs-member');
        $this->assertIsArray($row);
        $this->assertSame((string) $member->getKey(), $row['employeeId']);
        $this->assertNotSame('', $row['employeeName']);
        $this->assertSame('203.0.113.40', $row['ipAddress']);

        $userIds = array_column($response->json('filters.users'), 'value');
        $this->assertContains((string) $member->getKey(), $userIds);

        $filtered = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/administration/all-logs?employee_id='.$member->getKey().'&per_page=100')
            ->assertOk();
        $this->assertContains('all-logs-member', array_column($filtered->json('data'), 'recordId'));
        foreach ($filtered->json('data') as $item) {
            $this->assertIsArray($item);
            $this->assertSame((string) $member->getKey(), $item['employeeId']);
        }
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function adminWhoIsNotExecutive(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->assertOk()->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
