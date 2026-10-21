<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalClashFlowProjectsTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/development/portal/clashflow';

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_guest_cannot_use_clashflow(): void
    {
        $this->getJson(self::BASE.'/projects')->assertStatus(401);
        $this->getJson(self::BASE.'/states')->assertStatus(401);
    }

    public function test_a_team_project_gives_lead_coordinator_and_viewer_roles_and_hides_it_from_others(): void
    {
        $this->employee('cf-admin', 'Admin');
        $lead = $this->employee('cf-lead', 'ProjectAdmin');
        $member = $this->employee('cf-member', 'User');
        $this->employee('cf-outsider', 'User');
        $portal = $this->portalProject('CF-TEST-001', 'cf-lead');
        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $member, 'project_id' => $portal, 'role_on_project' => 'member']);

        // Nothing exists until someone creates it.
        $this->assertSame([], $this->projectsFor('cf-member'));

        $admin = $this->tokenFor('cf-admin');
        $this->withToken($admin)->getJson(self::BASE.'/linkable?search=CF-TEST-001')->assertOk()->assertJsonPath('data.0.id', $portal);
        $created = $this->withToken($admin)->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $portal])
            ->assertCreated()->assertJsonPath('data.role', 'viewer')->json('data');
        $this->assertSame('CF-TEST-001', $created['number']);
        $this->assertSame([$lead, $member], array_column($created['team'], 'id'));

        // One ClashFlow project per portal project.
        $this->withToken($admin)->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $portal])->assertStatus(403);
        $this->assertSame([], $this->withToken($admin)->getJson(self::BASE.'/linkable?search=CF-TEST-001')->json('data'));

        $this->assertSame('lead', $this->projectsFor('cf-lead')[0]['role']);
        $this->assertSame('coordinator', $this->projectsFor('cf-member')[0]['role']);
        $this->assertSame([], $this->projectsFor('cf-outsider'));
    }

    public function test_a_project_admin_links_only_projects_they_lead_and_a_user_links_none(): void
    {
        $this->employee('cf-lead', 'ProjectAdmin');
        $this->employee('cf-other-lead', 'ProjectAdmin');
        $this->employee('cf-user', 'User');
        $own = $this->portalProject('CF-TEST-010', 'cf-lead');
        $theirs = $this->portalProject('CF-TEST-011', 'cf-other-lead');

        $lead = $this->tokenFor('cf-lead');
        $this->withToken($lead)->getJson(self::BASE.'/projects')->assertJsonPath('canCreateTeam', true);
        $this->assertSame([$own], array_column($this->withToken($lead)->getJson(self::BASE.'/linkable?search=CF-TEST-01')->json('data'), 'id'));
        $this->withToken($lead)->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $theirs])->assertStatus(403);
        $this->withToken($lead)->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $own])->assertCreated()->assertJsonPath('data.role', 'lead');

        $user = $this->tokenFor('cf-user');
        $this->withToken($user)->getJson(self::BASE.'/projects')->assertJsonPath('canCreateTeam', false);
        $this->assertSame([], $this->withToken($user)->getJson(self::BASE.'/linkable')->json('data'));
        $this->withToken($user)->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $theirs])->assertStatus(403);
    }

    public function test_a_solo_project_belongs_to_its_creator_alone(): void
    {
        $this->employee('cf-user', 'User');
        $this->employee('cf-admin', 'Admin');

        $user = $this->tokenFor('cf-user');
        $this->withToken($user)->postJson(self::BASE.'/projects', ['mode' => 'solo'])->assertStatus(422);
        $solo = $this->withToken($user)->postJson(self::BASE.'/projects', ['mode' => 'solo', 'name' => 'My tower', 'number' => 'S-1'])
            ->assertCreated()->json('data');
        $this->assertSame(['solo', 'lead', 'My tower', 'S-1', null], [$solo['mode'], $solo['role'], $solo['name'], $solo['number'], $solo['portalProjectId']]);

        $this->assertNotContains($solo['id'], array_column($this->projectsFor('cf-admin'), 'id'));
        $this->withToken($this->tokenFor('cf-admin'))->putJson(self::BASE.'/states/project-'.$solo['id'], ['state' => '{}'])->assertStatus(403);
    }

    public function test_workflow_state_is_written_by_the_team_and_only_read_by_viewers(): void
    {
        $this->employee('cf-admin', 'Admin');
        $this->employee('cf-lead', 'ProjectAdmin');
        $member = $this->employee('cf-member', 'User');
        $this->employee('cf-outsider', 'User');
        $portal = $this->portalProject('CF-TEST-020', 'cf-lead');
        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $member, 'project_id' => $portal, 'role_on_project' => 'member']);
        $id = $this->withToken($this->tokenFor('cf-lead'))->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $portal])->json('data.id');
        $key = 'project-'.$id;
        $states = self::BASE.'/states';
        $member = $this->tokenFor('cf-member');
        $admin = $this->tokenFor('cf-admin');
        $outsider = $this->tokenFor('cf-outsider');

        $this->withToken($member)->putJson("$states/$key", ['state' => '{"run":2}'])->assertNoContent();
        $this->withToken($admin)->getJson($states)->assertOk()->assertJsonPath("data.$key", '{"run":2}');
        $this->withToken($admin)->putJson("$states/$key", ['state' => '{"run":9}'])->assertStatus(403);
        $this->withToken($outsider)->putJson("$states/$key", ['state' => '{"run":9}'])->assertStatus(403);
        $this->assertArrayNotHasKey($key, $this->withToken($outsider)->getJson($states)->json('data'));

        $this->withToken($outsider)->putJson("$states/library", ['state' => '{"versions":[]}'])->assertNoContent();
        $this->withToken($member)->putJson("$states/project-abc", ['state' => '{}'])->assertStatus(404);
        $this->withToken($member)->putJson("$states/$key", ['state' => '{not json'])->assertStatus(422);

        // A viewer's reset leaves the project alone; the team's reset clears it and the library.
        $this->withToken($admin)->deleteJson($states)->assertNoContent();
        $this->assertTrue(DB::connection('portal')->table('clashflow_states')->where('state_key', $key)->exists());
        $this->withToken($member)->deleteJson($states)->assertNoContent();
        $this->assertSame([], DB::connection('portal')->table('clashflow_states')->whereIn('state_key', [$key, 'library'])->pluck('state_key')->all());
    }

    private function employee(string $name, string $role): int
    {
        return DB::connection('portal')->table('employees')->insertGetId([
            'id_no' => 'CF-'.$name,
            'first_name' => 'Clash',
            'last_name' => $name,
            'email' => $name.'@clashflow.test',
            'status' => 'Active',
            'role' => $role,
        ]);
    }

    private function portalProject(string $number, string $leadName): int
    {
        return DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => $number,
            'project_name' => 'ClashFlow test '.$number,
            'project_lead_email' => $leadName.'@clashflow.test',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectsFor(string $name): array
    {
        return $this->withToken($this->tokenFor($name))->getJson(self::BASE.'/projects')->assertOk()->json('data');
    }

    private function tokenFor(string $name): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => 'CF-'.$name])->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
