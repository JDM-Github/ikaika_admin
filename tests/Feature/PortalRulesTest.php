<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Enforcement: a page that follows a rule answers only while the person holds it and has not
 * switched it off, and the session names the active rules so the portal can hide the rest.
 * Home is the first page to follow its rule.
 */
class PortalRulesTest extends TestCase
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
        foreach (['access_grants', 'access_limits', 'access_toggles', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
    }

    // Switching a page over changes nothing on day one: every role starts with Home.
    public function test_every_role_holds_home_by_default(): void
    {
        foreach (['member', 'admin', 'executive', 'developer'] as $role) {
            $this->assertTrue(
                DB::connection('portal')->table('access_presets')->where('role', $role)->where('permission_key', 'home')->exists(),
                "The {$role} preset is missing Home.",
            );
        }
    }

    public function test_home_answers_only_while_it_is_switched_on(): void
    {
        $token = $this->login($this->member());
        $this->withToken($token)->getJson('/api/development/portal/home')->assertOk();
        $this->assertArrayHasKey('home', $this->rules($token));

        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'home', 'on' => false])->assertOk();

        $this->withToken($token)->getJson('/api/development/portal/home')
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this page.');
        $this->assertArrayNotHasKey('home', $this->rules($token));

        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'home', 'on' => true])->assertOk();
        $this->withToken($token)->getJson('/api/development/portal/home')->assertOk();
    }

    // A limit from someone who manages them takes Home away just the same.
    public function test_a_limit_takes_home_away(): void
    {
        $member = $this->member();
        $admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($admin);
        $admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $member->forceFill(['department' => 'MIS'])->save();

        $this->withToken($this->login($admin))
            ->patchJson("/api/development/portal/manage/access/{$member->id}", ['key' => 'home', 'action' => 'off'])
            ->assertOk();

        $this->withToken($this->login($member))->getJson('/api/development/portal/home')->assertForbidden();
    }

    public function test_the_session_names_each_active_rule_with_its_reach(): void
    {
        $rules = $this->rules($this->login($this->member()));

        $this->assertArrayHasKey('home', $rules);
        $this->assertArrayHasKey('access', $rules);
        $this->assertArrayNotHasKey('manage', $rules);
    }

    // Track Projects keeps today's lists on day one: members their own projects, everyone else every project.
    public function test_track_projects_presets_match_today(): void
    {
        $reaches = DB::connection('portal')->table('access_presets')->where('permission_key', 'projects.track')->pluck('reach', 'role')->all();

        $this->assertSame(['admin' => 'all', 'developer' => 'all', 'executive' => 'all', 'member' => 'own'], collect($reaches)->sortKeys()->all());
    }

    public function test_track_projects_answers_only_while_it_is_switched_on(): void
    {
        $token = $this->login($this->member());
        $this->withToken($token)->getJson('/api/development/portal/projects/track')->assertOk()->assertJsonPath('resource', 'board');

        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => 'projects.track', 'on' => false])->assertOk();

        $this->withToken($token)->getJson('/api/development/portal/projects/track')->assertForbidden();
        $this->assertArrayNotHasKey('projects.track', $this->rules($token));
    }

    // Own = the projects they are on; department adds their department's; all is every project.
    public function test_track_projects_follows_the_reach_they_narrow_themselves_to(): void
    {
        $admin = $this->admin();
        $token = $this->login($admin);
        $total = DB::connection('portal')->table('projects')->count();

        $all = $this->tracked($token);
        $this->assertCount($total, $all);
        $this->assertSame('all', $this->rules($token)['projects.track']);

        $this->reach($token, 'department')->assertOk();
        $department = $this->tracked($token);
        $this->assertSame('department', $this->rules($token)['projects.track']);
        foreach ($department as $project) {
            $this->assertTrue($project['isAssigned'] || strtolower($project['departmentName']) === 'mis', "{$project['name']} is outside the reach.");
        }
        $this->assertLessThan($total, count($department));

        $this->reach($token, 'own')->assertOk();
        foreach ($this->tracked($token) as $project) {
            $this->assertTrue($project['isAssigned'], "{$project['name']} is not theirs.");
        }

        // Back to what they hold removes the narrowing, and asking again logs nothing new.
        $this->reach($token, 'all')->assertOk()->assertJsonPath('data.0.key', 'home');
        $this->assertCount($total, $this->tracked($token));
        $this->assertFalse(DB::connection('portal')->table('access_self_reach')->where('employee_id', $admin->id)->exists());
        $logs = DB::connection('portal')->table('logs')->where('employee_id', $admin->id)->where('resource', 'access.reach')->count();
        $this->reach($token, 'all')->assertOk();
        $this->assertSame($logs, DB::connection('portal')->table('logs')->where('employee_id', $admin->id)->where('resource', 'access.reach')->count());
    }

    public function test_a_self_reach_never_widens_and_needs_a_reach_rule_they_hold(): void
    {
        $token = $this->login($this->member());

        $this->reach($token, 'all')->assertStatus(422);
        $this->reach($token, 'own')->assertOk();
        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'home', 'reach' => 'own'])->assertStatus(422);
        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'manage', 'reach' => 'own'])->assertNotFound();
        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'projects.track', 'reach' => 'galaxy'])->assertStatus(422);
    }

    // Reports follow their rules from day one without anyone losing anything: every role holds them all.
    public function test_every_role_holds_every_report_rule(): void
    {
        $keys = ['reports', 'reports.daily', 'reports.late', 'reports.submitted', 'reports.submitted.edit', 'reports.submitted.delete', 'reports.submitted.export'];
        foreach (['member', 'admin', 'executive', 'developer'] as $role) {
            $held = DB::connection('portal')->table('access_presets')->where('role', $role)->whereIn('permission_key', $keys)->pluck('permission_key')->all();
            $this->assertEqualsCanonicalizing($keys, $held, "The {$role} preset is missing a report rule.");
        }
    }

    public function test_each_report_route_refuses_once_its_rule_is_off(): void
    {
        $token = $this->login($this->member());
        $this->withToken($token)->getJson('/api/development/portal/reports/submitted')->assertOk();

        foreach (['reports.daily', 'reports.submitted.edit', 'reports.submitted.delete'] as $key) {
            $this->switchOff($token, $key);
        }

        $this->withToken($token)->postJson('/api/development/portal/reports/submitted', ['kind' => 'daily', 'reports' => []])
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to this page.');
        $this->withToken($token)->patchJson('/api/development/portal/reports/submitted/2026-09-01-daily', [])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/development/portal/reports/submitted/2026-09-01-daily')->assertForbidden();
        // Late Report still reads the projects and the filed days it needs.
        $this->withToken($token)->getJson('/api/development/portal/reports/projects')->assertOk();
        $this->withToken($token)->getJson('/api/development/portal/reports/submitted/days')->assertOk();

        $this->switchOff($token, 'reports');
        foreach (['/reports/projects', '/reports/submitted', '/reports/submitted/days'] as $path) {
            $this->withToken($token)->getJson('/api/development/portal'.$path)->assertForbidden();
        }
        $this->withToken($token)->postJson('/api/development/portal/reports/submitted', ['kind' => 'late', 'reports' => []])->assertForbidden();
    }

    private function switchOff(string $token, string $key): void
    {
        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => $key, 'on' => false])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tracked(string $token): array
    {
        return $this->withToken($token)->getJson('/api/development/portal/projects/track')->assertOk()->json('data');
    }

    private function reach(string $token, string $reach): TestResponse
    {
        return $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'projects.track', 'reach' => $reach]);
    }

    private function admin(): Employee
    {
        $admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($admin);
        $admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $token): array
    {
        $rules = $this->withToken($token)->getJson('/api/development/portal/auth/me')->assertOk()->json('employee.rules');
        $this->assertIsArray($rules);

        return $rules;
    }

    private function member(): Employee
    {
        $member = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($member);
        $member->forceFill(['is_developer' => false])->save();

        return $member;
    }

    private function login(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }
}
