<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalLogAction;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
                'action',
                'resource',
                'recordId',
                'message',
                'createdAt',
                'dateLabel',
                'timeLabel',
                'createdAtLabel',
            ], array_keys($row));
            $this->assertSame('PATCH', $row['action']);
            $this->assertSame('requests.leave', $row['resource']);
            $this->assertSame('{UserName|You} updated an owned request', $row['message']);
            $this->assertSame('Monday, September 7, 2026', $row['dateLabel']);
            $this->assertSame('8:35 AM', $row['timeLabel']);
            $this->assertSame('Monday, September 7, 2026 at 8:35 AM', $row['createdAtLabel']);
            $this->assertArrayNotHasKey('ipAddress', $row);
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
