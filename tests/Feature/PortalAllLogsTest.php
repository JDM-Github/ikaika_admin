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

    public function test_an_admin_reads_any_members_log_detail_with_its_payload(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $token = $this->loginToken($admin);
        $audit = app(PortalAudit::class);

        $logId = $audit->record(
            $member,
            PortalLogAction::PATCH,
            'requests.leave',
            '{UserName|You} updated an owned request',
            'all-logs-detail',
            null,
            ['leaveType' => 'Vacation Leave', 'requestedFor' => '2026-08-24', 'reason' => 'Family trip'],
        );

        $this->withToken($token)
            ->getJson("/api/development/portal/administration/all-logs/{$logId}")
            ->assertOk()
            ->assertJsonPath('section', 'administration')
            ->assertJsonPath('resource', 'all-logs')
            ->assertJsonPath('data.recordId', 'all-logs-detail')
            ->assertJsonPath('data.employeeId', (string) $member->getKey())
            ->assertJsonPath('data.payload.leaveType', 'Vacation Leave')
            ->assertJsonPath('data.payload.reason', 'Family trip');
    }

    public function test_a_member_cannot_read_a_log_detail_through_all_logs(): void
    {
        $member = $this->activeMember();
        $token = $this->loginToken($member);
        $audit = app(PortalAudit::class);
        $logId = $audit->record(
            $member,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added a report',
            'member-blocked',
        );

        $this->withToken($token)
            ->getJson("/api/development/portal/administration/all-logs/{$logId}")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_a_log_detail_through_all_logs(): void
    {
        $member = $this->activeMember();
        $audit = app(PortalAudit::class);
        $logId = $audit->record(
            $member,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added a report',
            'guest-blocked',
        );

        $this->getJson("/api/development/portal/administration/all-logs/{$logId}")
            ->assertUnauthorized();
    }

    public function test_a_log_with_no_payload_returns_an_object_not_an_empty_array(): void
    {
        $member = $this->activeMember();
        $token = $this->loginToken($this->adminWhoIsNotExecutive());
        $audit = app(PortalAudit::class);
        // No payload argument at all -- exactly what auth.login and every untouched call site write.
        $logId = $audit->record(
            $member,
            PortalLogAction::POST,
            'auth.login',
            '{UserName|You} signed in to the portal',
            (string) $member->getKey(),
        );

        $firstRead = $this->withToken($token)
            ->getJson("/api/development/portal/administration/all-logs/{$logId}")
            ->assertOk();
        // Read again so this exercises a cache *hit*, not just the miss that populated it -- the
        // file cache's unserialize breaks an object payload on the read path, not the write path,
        // so a test that only reads once cannot catch it.
        $secondRead = $this->withToken($token)
            ->getJson("/api/development/portal/administration/all-logs/{$logId}")
            ->assertOk();

        // An empty PHP array has no keys to say map or list, so json_encode renders it as `[]`
        // unless forced -- assert the raw body, since json_decode would silently hide the bug.
        $this->assertStringContainsString('"payload":{}', $firstRead->getContent());
        $this->assertStringContainsString('"payload":{}', $secondRead->getContent());
    }

    public function test_the_real_coordinate_surfaces_on_both_the_list_row_and_the_detail(): void
    {
        $member = $this->activeMember();
        $token = $this->loginToken($this->adminWhoIsNotExecutive());
        $audit = app(PortalAudit::class);

        $logId = $audit->record(
            $member,
            PortalLogAction::PATCH,
            'requests.leave',
            '{UserName|You} updated an owned request',
            'all-logs-pin',
            Request::create(
                '/api/development/portal/requests/leave/41',
                'PATCH',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '203.0.113.17',
                    'HTTP_X_PORTAL_LOCATION' => 'Parian, Calamba City',
                    'HTTP_X_PORTAL_LOCATION_LAT' => '14.2117',
                    'HTTP_X_PORTAL_LOCATION_LNG' => '121.1642',
                ],
            ),
        );

        $listed = $this->withToken($token)
            ->getJson('/api/development/portal/administration/all-logs?per_page=100')
            ->assertOk();
        $row = collect($listed->json('data'))->firstWhere('recordId', 'all-logs-pin');
        $this->assertIsArray($row);
        $this->assertSame(14.2117, $row['locationLat']);
        $this->assertSame(121.1642, $row['locationLng']);

        $this->withToken($token)
            ->getJson("/api/development/portal/administration/all-logs/{$logId}")
            ->assertOk()
            ->assertJsonPath('data.locationLat', 14.2117)
            ->assertJsonPath('data.locationLng', 121.1642);
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
