<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalAllActionsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Both: the ledger rows land on core while the actor names are read from portal.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core', 'portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    public function test_catalog_lists_all_actions_under_administration(): void
    {
        $sections = $this->getJson('/api/development/portal')
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);
        $administration = collect($sections)->firstWhere('name', 'administration');
        $this->assertIsArray($administration);
        $resource = collect($administration['resources'] ?? [])->firstWhere('name', 'all-actions');
        $this->assertIsArray($resource);
        $this->assertSame('All Actions', $resource['label'] ?? null);
        $this->assertTrue($resource['requires_admin'] ?? false);
        $this->assertSame(
            '/api/development/portal/administration/all-actions',
            $resource['url'] ?? null,
        );
    }

    public function test_a_guest_cannot_read_all_actions(): void
    {
        $this->getJson('/api/development/portal/administration/all-actions')
            ->assertUnauthorized();
    }

    public function test_a_member_cannot_read_all_actions(): void
    {
        $member = $this->activeMember();
        $token = $this->loginToken($member);

        $this->withToken($token)
            ->getJson('/api/development/portal/administration/all-actions')
            ->assertForbidden();
    }

    public function test_an_admin_reads_the_ledger_with_the_actors_real_name(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $token = $this->loginToken($admin);

        $this->recordAdd($member, 'all-actions-name');

        $response = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/administration/all-actions?per_page=100&q=all-actions-name')
            ->assertOk()
            ->assertJsonPath('section', 'administration')
            ->assertJsonPath('resource', 'all-actions');

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $row = collect($rows)->firstWhere('recordId', 'all-actions-name');
        $this->assertIsArray($row);

        // The name is joined from the portal connection, which the ledger cannot reach in SQL.
        $this->assertSame(trim($member->first_name.' '.$member->last_name), $row['employeeName']);
        $this->assertSame((int) $member->getKey(), $row['employeeId']);
        $this->assertSame($member->id_no, $row['employeeIdNo']);
        $this->assertSame('add', $row['actionType']);
        $this->assertSame('portal', $row['product']);
        $this->assertSame('reports.submitted', $row['resource']);

        $this->assertArrayHasKey('actionTypes', $response->json('filters'));
        $this->assertArrayHasKey('databaseTargets', $response->json('filters'));
        $this->assertArrayHasKey('actors', $response->json('filters'));
        $this->assertIsInt($response->json('meta.total'));
        $this->assertIsInt($response->json('counts.total'));
    }

    public function test_the_detail_returns_the_written_payload_without_the_envelope(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $token = $this->loginToken($admin);

        $actionId = $this->recordAdd($member, 'all-actions-detail');

        $response = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/administration/all-actions/'.$actionId)
            ->assertOk()
            ->assertJsonPath('section', 'administration')
            ->assertJsonPath('resource', 'all-actions')
            ->assertJsonPath('data.payload.kind', 'daily');

        // CoreLedger wraps the payload in an envelope whose keys are already columns -- the detail
        // hands back the inner parameters only, so the caller never sees them twice.
        $payload = $response->json('data.payload');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('action_type', $payload);
        $this->assertArrayNotHasKey('database_target', $payload);
    }

    public function test_an_unknown_action_is_not_found(): void
    {
        $token = $this->loginToken($this->adminWhoIsNotExecutive());

        $this->withToken($token)
            ->getJson('/api/development/portal/administration/all-actions/99999999')
            ->assertNotFound();
    }

    public function test_the_day_filter_resolves_in_the_callers_timezone(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $token = $this->loginToken($admin);

        $actionId = $this->recordAdd($member, 'all-actions-timezone');
        $createdAt = Action::query()->findOrFail($actionId)->created_at;
        $this->assertNotNull($createdAt);

        // The same instant falls on one calendar day in Manila and possibly another in UTC. Each
        // zone must find the row on its OWN day, which a server-side whereDate could not do.
        foreach (['Asia/Manila', 'UTC'] as $zone) {
            Cache::flush();
            $day = $createdAt->copy()->setTimezone($zone)->format('Y-m-d');

            $rows = $this->withToken($token)
                ->withHeaders([PortalTimezone::NAME_HEADER => $zone])
                ->getJson('/api/development/portal/administration/all-actions?per_page=100&day='.$day)
                ->assertOk()
                ->json('data');

            $this->assertIsArray($rows);
            $this->assertNotNull(
                collect($rows)->firstWhere('id', (string) $actionId),
                "The row should be on {$day} in {$zone}.",
            );
        }
    }

    public function test_the_action_type_filter_narrows_the_ledger(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $token = $this->loginToken($admin);

        $rows = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/administration/all-actions?per_page=100&action_type=delete')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertSame('delete', $row['actionType']);
        }
    }

    private function recordAdd(Employee $member, string $recordId): int
    {
        return app(CoreLedger::class)->recordAdd(
            CoreLedger::PRODUCT_PORTAL,
            $member->getKey().':'.$recordId,
            'portal.user_reports',
            ['id' => $recordId, 'kind' => 'daily', 'entries' => []],
            'reports.submitted',
            $recordId,
            (int) $member->getKey(),
            $member->id_no,
        );
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
