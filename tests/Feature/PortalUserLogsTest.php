<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalLogAction;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PortalUserLogsTest extends TestCase
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
    }

    public function test_catalog_lists_user_logs(): void
    {
        $sections = $this->getJson('/api/development/portal')
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);

        $user = collect($sections)->firstWhere('name', 'user');
        $this->assertIsArray($user);
        $this->assertSame('Logs', $user['resources'][0]['label'] ?? null);
        $this->assertSame(
            '/api/development/portal/user/logs',
            $user['resources'][0]['url'] ?? null,
        );
        $this->assertSame('GET', $user['resources'][0]['operations'][0]['method'] ?? null);
    }

    public function test_a_guest_cannot_read_user_logs(): void
    {
        $this->getJson('/api/development/portal/user/logs')
            ->assertUnauthorized();
    }

    public function test_a_member_reads_all_of_their_own_skinny_logs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 08:35:00', 'UTC'));

        try {
            $actor = $this->activeMember();
            $other = $this->otherActiveMember($actor);
            $before = PortalLog::query()
                ->where('employee_id', (int) $actor->getKey())
                ->count();
            $token = $this->loginToken($actor);

            $audit = app(PortalAudit::class);
            $audit->record(
                $actor,
                PortalLogAction::INSERT,
                'reports.submitted',
                '{UserName|You} added an owned report',
                'owned-insert',
            );
            $audit->record(
                $actor,
                PortalLogAction::PATCH,
                'requests.leave',
                '{UserName|You} updated an owned request',
                'owned-patch',
                Request::create(
                    '/api/development/portal/requests/leave/41',
                    'PATCH',
                    [],
                    [],
                    [],
                    [
                        'REMOTE_ADDR' => '203.0.113.17',
                        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.0.0 Mobile/15E148 Safari/604.1',
                        'HTTP_X_PORTAL_LOCATION' => 'Parian, Calamba City',
                        'HTTP_X_PORTAL_LOCATION_SOURCE' => 'ip',
                    ],
                ),
            );
            $audit->record(
                $actor,
                PortalLogAction::DELETE,
                'reports.submitted',
                '{UserName|You} deleted an owned report',
                'owned-delete',
            );
            $audit->record(
                $other,
                PortalLogAction::INSERT,
                'reports.submitted',
                'OTHER-EMPLOYEE-SECRET',
                'other-insert',
            );

            $response = $this->withToken($token)
                ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
                ->getJson('/api/development/portal/user/logs?per_page=100')
                ->assertOk()
                ->assertJsonPath('section', 'user')
                ->assertJsonPath('resource', 'logs');

            $data = $response->json('data');
            $this->assertIsArray($data);
            $this->assertSame($before + 4, $response->json('counts.total'));
            $this->assertSame($before + 4, $response->json('meta.total'));
            $this->assertContains('owned-insert', array_column($data, 'recordId'));
            $this->assertContains('owned-patch', array_column($data, 'recordId'));
            $this->assertContains('owned-delete', array_column($data, 'recordId'));
            $this->assertNotContains('other-insert', array_column($data, 'recordId'));
            $this->assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $response->getContent());

            $row = collect($data)->firstWhere('recordId', 'owned-patch');
            $this->assertIsArray($row);
            $this->assertSame([
                'id',
                'employeeId',
                'employeeName',
                'action',
                'resource',
                'recordId',
                'message',
                'ipAddress',
                'deviceLabel',
                'locationLabel',
                'locationSource',
                'locationLat',
                'locationLng',
                'createdAt',
                'dateLabel',
                'timeLabel',
                'createdAtLabel',
            ], array_keys($row));
            $this->assertSame('PATCH', $row['action']);
            $this->assertSame('requests.leave', $row['resource']);
            $this->assertSame('{UserName|You} updated an owned request', $row['message']);
            $this->assertSame('203.0.113.17', $row['ipAddress']);
            $this->assertSame('Google Chrome on iPhone', $row['deviceLabel']);
            $this->assertSame('Parian, Calamba City', $row['locationLabel']);
            $this->assertSame('ip', $row['locationSource']);
            $this->assertSame('Monday, September 7, 2026', $row['dateLabel']);
            $this->assertSame('8:35 AM', $row['timeLabel']);
            $this->assertSame('Monday, September 7, 2026 at 8:35 AM', $row['createdAtLabel']);
            $this->assertArrayNotHasKey('userAgent', $row);
            $this->assertArrayNotHasKey('payload', $row);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_logs_support_action_search_and_cache_refresh(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);

        $this->withToken($token)
            ->getJson('/api/development/portal/user/logs?q=cache-check')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $audit->record(
            $actor,
            PortalLogAction::PATCH,
            'requests.leave',
            '{UserName|You} changed the cache-check request',
            'cache-check',
        );
        $audit->record(
            $actor,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added the cache-check report',
            'cache-check-report',
        );

        $response = $this->withToken($token)
            ->getJson('/api/development/portal/user/logs?action=patch&q=cache-check request&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertSame(['cache-check'], array_column($data, 'recordId'));
        $this->assertSame('PATCH', $data[0]['action']);
    }

    public function test_logs_filter_by_available_action_activity_ip_and_dates(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);

        Carbon::setTestNow(Carbon::parse('2025-06-10 10:00:00', 'UTC'));
        $juneRequest = Request::create(
            '/api/development/portal/reports/submitted',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.10'],
        );
        $audit->record(
            $actor,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added a June report',
            'june-2025',
            $juneRequest,
        );

        Carbon::setTestNow(Carbon::parse('2026-06-18 11:00:00', 'UTC'));
        $audit->record(
            $actor,
            PortalLogAction::PATCH,
            'requests.leave',
            '{UserName|You} updated a June leave',
            'june-2026',
            Request::create(
                '/api/development/portal/requests/leave/1',
                'PATCH',
                [],
                [],
                [],
                ['REMOTE_ADDR' => '203.0.113.11'],
            ),
        );

        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:00', 'UTC'));
        $audit->record(
            $actor,
            PortalLogAction::DELETE,
            'reports.submitted',
            '{UserName|You} deleted a July report',
            'july-2026',
        );

        $audit->record(
            $other,
            PortalLogAction::POST,
            'manage.users',
            'OTHER-FACET',
            'other-login',
        );

        Carbon::setTestNow();

        $page = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/logs?per_page=100')
            ->assertOk();

        $filters = $page->json('filters');
        $this->assertIsArray($filters);
        $this->assertContains('INSERT', $filters['actions']);
        $this->assertContains('PATCH', $filters['actions']);
        $this->assertContains('DELETE', $filters['actions']);
        $this->assertContains('reports.submitted', $filters['resources']);
        $this->assertContains('requests.leave', $filters['resources']);
        $this->assertNotContains('manage.users', $filters['resources']);
        $this->assertContains('203.0.113.10', $filters['ipAddresses']);
        $this->assertContains('203.0.113.11', $filters['ipAddresses']);
        $this->assertContains('2025', $filters['years']);
        $this->assertContains('2026', $filters['years']);
        $monthValues = array_column($filters['months'], 'value');
        $this->assertContains('06', $monthValues);
        $this->assertContains('07', $monthValues);
        $this->assertContains('June', array_column($filters['months'], 'label'));

        $june = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/logs?month=6&per_page=100')
            ->assertOk();
        $juneIds = array_column($june->json('data'), 'recordId');
        $this->assertContains('june-2025', $juneIds);
        $this->assertContains('june-2026', $juneIds);
        $this->assertNotContains('july-2026', $juneIds);
        $this->assertNotContains('manage.users', $june->json('filters.resources'));

        $day = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/logs?day=2026-06-18&per_page=100')
            ->assertOk();
        $this->assertSame(['june-2026'], array_column($day->json('data'), 'recordId'));

        $ip = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/logs?ip=203.0.113.10&per_page=100')
            ->assertOk();
        $this->assertSame(['june-2025'], array_column($ip->json('data'), 'recordId'));

        $activity = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/logs?resource=requests.leave&per_page=100')
            ->assertOk();
        $this->assertSame(['june-2026'], array_column($activity->json('data'), 'recordId'));

        $ignored = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/logs?month=13&per_page=100')
            ->assertOk();
        $ignoredIds = array_column($ignored->json('data'), 'recordId');
        $this->assertContains('july-2026', $ignoredIds);
        $this->assertNotContains('other-login', $ignoredIds);
    }

    public function test_a_member_reads_their_own_log_detail_with_its_payload(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);

        $logId = $audit->record(
            $actor,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added a daily report',
            'detail-report',
            null,
            [
                'kind' => 'daily',
                'submittedOn' => '2026-08-24',
                'remarks' => 'Site inspection',
                'entries' => [
                    [
                        'id' => '1',
                        'projectLabel' => 'IKAIKA Tower',
                        'activityLabel' => 'Structural steel',
                        'earnCodeLabel' => 'Regular',
                        'hoursRendered' => 8.0,
                        'elementChange' => 0.0,
                    ],
                ],
            ],
        );

        $response = $this->withToken($token)
            ->getJson("/api/development/portal/user/logs/{$logId}")
            ->assertOk()
            ->assertJsonPath('section', 'user')
            ->assertJsonPath('resource', 'logs')
            ->assertJsonPath('data.recordId', 'detail-report')
            ->assertJsonPath('data.payload.kind', 'daily')
            ->assertJsonPath('data.payload.submittedOn', '2026-08-24')
            ->assertJsonPath('data.payload.remarks', 'Site inspection')
            ->assertJsonPath('data.payload.entries.0.projectLabel', 'IKAIKA Tower')
            // A whole-number float round-trips through the JSON column as an integer.
            ->assertJsonPath('data.payload.entries.0.hoursRendered', 8);

        $this->assertArrayHasKey('payload', $response->json('data'));
    }

    public function test_a_member_cannot_read_someone_elses_log_detail(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);

        $logId = $audit->record(
            $other,
            PortalLogAction::INSERT,
            'reports.submitted',
            'OTHER-EMPLOYEE-SECRET',
            'not-yours',
        );

        $response = $this->withToken($token)
            ->getJson("/api/development/portal/user/logs/{$logId}")
            ->assertNotFound();
        $this->assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $response->getContent());
    }

    public function test_a_guest_cannot_read_a_log_detail(): void
    {
        $actor = $this->activeMember();
        $audit = app(PortalAudit::class);
        $logId = $audit->record(
            $actor,
            PortalLogAction::INSERT,
            'reports.submitted',
            '{UserName|You} added a report',
            'guest-blocked',
        );

        $this->getJson("/api/development/portal/user/logs/{$logId}")
            ->assertUnauthorized();
    }

    public function test_a_log_with_no_payload_returns_an_object_not_an_empty_array(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);
        // No payload argument at all -- exactly what auth.login and every untouched call site write.
        $logId = $audit->record(
            $actor,
            PortalLogAction::POST,
            'auth.login',
            '{UserName|You} signed in to the portal',
            (string) $actor->getKey(),
        );

        $firstRead = $this->withToken($token)
            ->getJson("/api/development/portal/user/logs/{$logId}")
            ->assertOk();
        // Read again so this exercises a cache *hit*, not just the miss that populated it -- the
        // file cache's unserialize breaks an object payload on the read path, not the write path,
        // so a test that only reads once cannot catch it.
        $secondRead = $this->withToken($token)
            ->getJson("/api/development/portal/user/logs/{$logId}")
            ->assertOk();

        // An empty PHP array has no keys to say map or list, so json_encode renders it as `[]`
        // unless forced -- assert the raw body, since json_decode would silently hide the bug.
        $this->assertStringContainsString('"payload":{}', $firstRead->getContent());
        $this->assertStringContainsString('"payload":{}', $secondRead->getContent());
    }

    public function test_the_real_coordinate_surfaces_on_both_the_list_row_and_the_detail(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);

        $logId = $audit->record(
            $actor,
            PortalLogAction::PATCH,
            'requests.leave',
            '{UserName|You} updated an owned request',
            'location-pin',
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
            ->getJson('/api/development/portal/user/logs?per_page=100')
            ->assertOk();
        $row = collect($listed->json('data'))->firstWhere('recordId', 'location-pin');
        $this->assertIsArray($row);
        $this->assertSame(14.2117, $row['locationLat']);
        $this->assertSame(121.1642, $row['locationLng']);

        $this->withToken($token)
            ->getJson("/api/development/portal/user/logs/{$logId}")
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

    private function otherActiveMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->where('id', '!=', $actor->getKey())
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $response = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
