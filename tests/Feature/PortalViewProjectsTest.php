<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalViewProjectsTest extends TestCase
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
    }

    public function test_catalog_lists_view_projects_after_home(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('sections.1.name', 'projects')
            ->assertJsonPath('sections.1.resources.0.name', 'board')
            ->assertJsonPath('sections.1.resources.0.url', '/api/development/portal/projects')
            ->assertJsonPath('sections.1.requires_admin', false);
    }

    public function test_guest_cannot_read_the_board(): void
    {
        $this->getJson('/api/development/portal/projects')->assertStatus(401);
    }

    public function test_a_member_sees_only_assigned_projects_marked_as_theirs(): void
    {
        $actor = $this->activeMember();
        $own = $this->ownProjectIds($actor);
        $this->assertNotEmpty($own);
        $unassigned = DB::connection('portal')->table('projects')
            ->whereNotIn('id', $own)
            ->whereNotNull('project_name')
            ->first();
        $this->assertNotNull($unassigned);

        $response = $this->withToken($this->loginToken($actor))
            ->getJson('/api/development/portal/projects')
            ->assertOk()
            ->assertJsonPath('section', 'projects')
            ->assertJsonPath('resource', 'board');

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['isAssigned']);
            $this->assertContains((int) $row['id'], $own);
            $this->assertArrayHasKey('code', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayHasKey('scopeSummary', $row);
            $this->assertArrayNotHasKey('bank', $row);
        }
        $this->assertNotContains((string) $unassigned->id, array_column($rows, 'id'));
    }

    public function test_an_admin_sees_all_projects_with_an_assignment_flag(): void
    {
        $actor = $this->activeAdmin();
        $own = $this->ownProjectIds($actor);
        $this->assertNotEmpty($own);
        $foreign = DB::connection('portal')->table('projects')
            ->whereNotIn('id', $own)
            ->whereNotNull('project_name')
            ->first();
        $this->assertNotNull($foreign);

        $response = $this->withToken($this->loginToken($actor))
            ->getJson('/api/development/portal/projects')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertGreaterThan(count($own), count($rows));

        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
        }

        $ownId = (string) $own[0];
        $this->assertArrayHasKey($ownId, $byId);
        $this->assertTrue($byId[$ownId]['isAssigned']);

        $foreignId = (string) $foreign->id;
        $this->assertArrayHasKey($foreignId, $byId);
        $this->assertFalse($byId[$foreignId]['isAssigned']);
    }

    public function test_project_board_returns_scope_metrics_details_and_trail_history(): void
    {
        $actor = $this->activeMember();
        $portal = DB::connection('portal');
        $projectId = $portal->table('projects')->insertGetId([
            'project_number' => '269999',
            'project_name' => 'Project Metrics Contract',
            'department' => 'Engineering',
            'status' => 'Started',
            'progress_pct' => 25,
            'type_of_job' => 'Scan-to-BIM',
            'area_sqft' => 12000,
            'project_lead_email' => $actor->email,
        ]);
        $portal->table('employees_projects')->insert([
            'employee_id' => $actor->getKey(),
            'project_id' => $projectId,
            'role_on_project' => 'Member',
        ]);

        $clientId = $portal->table('clients')->insertGetId(['name' => 'Metrics Client']);
        $portal->table('projects_clients')->insert([
            'project_id' => $projectId,
            'client_id' => $clientId,
        ]);

        foreach ([100, 50, 0, null] as $progress) {
            $scopeId = $portal->table('project_scopes')->insertGetId(['progress_pct' => $progress]);
            $portal->table('projects_project_scopes')->insert([
                'project_id' => $projectId,
                'project_scope_id' => $scopeId,
            ]);
        }

        $historyId = $portal->table('project_action_history')->insertGetId([
            'action_name' => 'Project Edited',
            'created_by' => 'Metrics Tester',
            'remarks' => 'Updated scope totals',
            'created_at' => '2026-09-01 08:00:00',
        ]);
        $portal->table('projects_action_history')->insert([
            'project_id' => $projectId,
            'project_action_id' => $historyId,
        ]);

        $response = $this->withToken($this->loginToken($actor))
            ->getJson('/api/development/portal/projects')
            ->assertOk();

        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('id', (string) $projectId);
        $this->assertIsArray($row);
        $this->assertSame('Metrics Client', $row['clientName']);
        $this->assertSame([
            'tierLabel' => '',
            'total' => 4,
            'completed' => 1,
            'inProgress' => 1,
            'overallCompletion' => 0.375,
        ], $row['scopeSummary']);
        $this->assertSame([
            [
                'id' => (string) $historyId,
                'changedOn' => '2026-09-01T08:00:00+00:00',
                'actorName' => 'Metrics Tester',
                'summary' => 'Project Edited: Updated scope totals',
            ],
        ], $row['trail']);
    }

    public function test_project_board_counts_scopes_reached_through_activity_links(): void
    {
        $actor = $this->activeMember();
        $portal = DB::connection('portal');
        $projectId = $portal->table('projects')->insertGetId([
            'project_number' => '269998',
            'project_name' => 'Project Activity Scope Contract',
            'department' => 'Engineering',
            'status' => 'Started',
            'project_lead_email' => $actor->email,
        ]);
        $portal->table('employees_projects')->insert([
            'employee_id' => $actor->getKey(),
            'project_id' => $projectId,
            'role_on_project' => 'Member',
        ]);

        $scopeId = $portal->table('project_scopes')->insertGetId(['id_no' => 500, 'progress_pct' => 50]);
        $activityId = $portal->table('project_scope_t3_activities')->insertGetId([
            'name' => 'Activity scope test',
            'process' => 'BIM Modeling',
        ]);
        $portal->table('project_scopes_t3_activities')->insert([
            'project_scope_id' => $scopeId,
            't3_activity_id' => $activityId,
        ]);
        $portal->table('projects_activity_scope')->insert([
            'project_id' => $projectId,
            'activity_id' => $activityId,
        ]);

        $row = collect($this->withToken($this->loginToken($actor))
            ->getJson('/api/development/portal/projects')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', (string) $projectId);

        $this->assertSame([
            'tierLabel' => '',
            'total' => 1,
            'completed' => 0,
            'inProgress' => 1,
            'overallCompletion' => 0.5,
        ], $row['scopeSummary']);
        $this->assertSame(['500'], $row['scopeCodes']);
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereIn('id', function ($query): void {
                $query->select('employee_id')->from('employees_projects');
            })
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function activeAdmin(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereIn('id', function ($query): void {
                $query->select('employee_id')->from('employees_projects');
            })
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @return list<int>
     */
    private function ownProjectIds(Employee $employee): array
    {
        return DB::connection('portal')->table('employees_projects')
            ->where('employee_id', $employee->getKey())
            ->pluck('project_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
