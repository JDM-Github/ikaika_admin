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

    public function test_workflow_state_is_read_and_written_only_by_people_on_the_project(): void
    {
        $this->employee('cf-lead');
        $member = $this->employee('cf-member');
        $outsider = $this->employee('cf-outsider');
        $project = DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => 'CF-TEST-010',
            'project_name' => 'ClashFlow state tower',
            'project_lead_email' => 'cf-lead@clashflow.test',
        ]);
        $other = DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => 'CF-TEST-011',
            'project_name' => 'Outsider tower',
        ]);
        DB::connection('portal')->table('employees_projects')->insert([
            ['employee_id' => $member, 'project_id' => $project, 'role_on_project' => 'member'],
            ['employee_id' => $outsider, 'project_id' => $other, 'role_on_project' => 'member'],
        ]);
        $url = '/api/development/portal/clashflow/states';
        $key = 'project-'.$project;
        $member = $this->tokenFor('cf-member');
        $lead = $this->tokenFor('cf-lead');
        $outsider = $this->tokenFor('cf-outsider');

        $this->withToken($member)->putJson("$url/$key", ['state' => '{"run":2}'])->assertNoContent();
        $this->withToken($lead)->getJson($url)->assertOk()->assertJsonPath("data.$key", '{"run":2}');

        // Not on the project: no read, no write.
        $this->withToken($outsider)->putJson("$url/$key", ['state' => '{"run":9}'])->assertStatus(403);
        $this->assertArrayNotHasKey($key, $this->withToken($outsider)->getJson($url)->json('data'));

        // The library is shared by everyone signed in.
        $this->withToken($outsider)->putJson("$url/library", ['state' => '{"versions":[]}'])->assertNoContent();
        $this->withToken($member)->getJson($url)->assertJsonPath('data.library', '{"versions":[]}');
        $this->withToken($outsider)->putJson("$url/project-$other", ['state' => '{"run":1}'])->assertNoContent();

        $this->withToken($member)->putJson("$url/project-abc", ['state' => '{}'])->assertStatus(404);
        $this->withToken($member)->putJson("$url/$key", ['state' => '{not json'])->assertStatus(422);
        $this->withToken($member)->putJson("$url/$key", [])->assertStatus(422);

        // Reset clears the person's projects and the library, never someone else's project.
        $this->withToken($member)->deleteJson($url)->assertNoContent();
        $left = DB::connection('portal')->table('clashflow_states')->whereIn('state_key', [$key, 'library', "project-$other"])->pluck('state_key')->all();
        $this->assertSame(["project-$other"], $left);

        $this->flushHeaders()->getJson($url)->assertStatus(401);
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
        return $this->withToken($this->tokenFor($name))
            ->getJson('/api/development/portal/clashflow/projects')
            ->assertOk()
            ->json('data');
    }

    private function tokenFor(string $name): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => 'CF-'.$name])->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
