<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalClashFlowProjectsTest extends TestCase
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

    public function test_guest_cannot_list_clashflow_projects(): void
    {
        $this->getJson('/api/development/portal/clashflow/projects')->assertStatus(401);
    }

    public function test_lead_and_member_each_see_the_project_with_their_role_and_the_team(): void
    {
        $lead = $this->employee('cf-lead');
        $member = $this->employee('cf-member');
        $outsider = $this->employee('cf-outsider');
        $project = DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => 'CF-TEST-001',
            'project_name' => 'ClashFlow test tower',
            'department' => 'Engineering',
            'status' => 'Active',
            'project_lead_email' => ' CF-LEAD@clashflow.test ',
        ]);
        $deleted = DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => 'CF-TEST-002',
            'project_name' => 'Deleted tower',
            'project_lead_email' => 'cf-lead@clashflow.test',
            'deleted_at' => now(),
        ]);
        // Led by email only, with no member row: still the lead's project.
        $ledOnly = DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => 'CF-TEST-003',
            'project_name' => 'Lead-only tower',
            'project_lead_email' => 'cf-lead@clashflow.test',
        ]);
        DB::connection('portal')->table('employees_projects')->insert([
            ['employee_id' => $member, 'project_id' => $project, 'role_on_project' => 'member'],
            ['employee_id' => $lead, 'project_id' => $project, 'role_on_project' => 'support'],
        ]);

        $asLead = $this->projectsFor('cf-lead');
        $this->assertSame([$ledOnly, $project], array_column($asLead, 'id'));
        $this->assertSame(['lead', 'lead'], array_column($asLead, 'role'));
        $this->assertSame('CF-TEST-001', $asLead[1]['number']);
        $this->assertSame([$lead], array_column($asLead[0]['team'], 'id'));
        $this->assertNotContains($deleted, array_column($asLead, 'id'));
        $team = collect($asLead[1]['team'])->keyBy('id');
        $this->assertSame('lead', $team[$lead]['role']);
        $this->assertSame('coordinator', $team[$member]['role']);
        $this->assertSame('cf-member@clashflow.test', $team[$member]['email']);
        $this->assertCount(2, $team);

        $asMember = $this->projectsFor('cf-member');
        $this->assertSame([$project], array_column($asMember, 'id'));
        $this->assertSame('coordinator', $asMember[0]['role']);

        $this->assertSame([], $this->projectsFor('cf-outsider'));
        $this->assertIsInt($outsider);
    }

    private function employee(string $name): int
    {
        return DB::connection('portal')->table('employees')->insertGetId([
            'id_no' => 'CF-'.$name,
            'first_name' => 'Clash',
            'last_name' => $name,
            'email' => $name.'@clashflow.test',
            'status' => 'Active',
            'role' => 'User',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectsFor(string $name): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => 'CF-'.$name])->json('token');
        $this->assertIsString($token);

        return $this->withToken($token)
            ->getJson('/api/development/portal/clashflow/projects')
            ->assertOk()
            ->json('data');
    }
}
