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
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
    }

    // Every role but executive starts with Home; executives leave a member's own pages out by design.
    public function test_every_role_holds_home_by_default(): void
    {
        foreach (['member', 'admin', 'developer'] as $role) {
            $this->assertTrue(
                DB::connection('portal')->table('access_presets')->where('role', $role)->where('permission_key', 'home')->exists(),
                "The {$role} preset is missing Home.",
            );
        }
    }

    // Executives start with every department, less the developer tools, a member's own pages, the housekeeping
    // pages and See developers' activity, which is given person by person.
    public function test_executives_start_with_everything_but_the_left_out_pages_at_every_department(): void
    {
        $catalog = DB::connection('portal')->table('access_permissions')->get()->keyBy('permission_key');
        $presets = DB::connection('portal')->table('access_presets')->where('role', 'executive')->get()->keyBy('permission_key');
        $leftOut = ['developer', 'home', 'reports', 'requests', 'user', 'administration.recycle', 'administration.actions', 'administration.backup', 'administration.logs.developers'];

        foreach ($catalog as $key => $rule) {
            $isLeftOut = collect($leftOut)->contains(static fn (string $root): bool => $key === $root || str_starts_with((string) $key, $root.'.'));
            if ($isLeftOut) {
                $this->assertFalse($presets->has($key), "Executives should not start with {$key}.");

                continue;
            }
            $this->assertTrue($presets->has($key), "Executives are missing {$key}.");
            $this->assertSame((bool) $rule->supports_reach ? 'all' : null, $presets[$key]->reach, "{$key} should reach every department.");
        }
    }

    // Only a developer in Developer mode holds the developer tools; no role starts with them and no gift reaches them.
    public function test_the_developer_tools_are_for_developers_only(): void
    {
        $tiers = DB::connection('portal')->table('access_permissions')->where('permission_key', 'like', 'developer%')->pluck('min_tier', 'permission_key');

        $this->assertCount(4, $tiers);
        $this->assertSame(['developer'], $tiers->unique()->values()->all());
        $this->assertFalse(DB::connection('portal')->table('access_presets')->whereIn('role', ['member', 'admin', 'executive'])->where('permission_key', 'like', 'developer%')->exists());
    }

    // Reports and All Recycle take white and black lists; admins start bound by them. Executives ignore
    // Reports' lists; All Recycle is not theirs by default, so only developers ignore its lists.
    public function test_reports_and_all_recycle_take_lists_that_admins_start_bound_by(): void
    {
        $this->assertEqualsCanonicalizing(['manage.reports', 'administration.recycle'], DB::connection('portal')->table('access_permissions')->where('supports_list', 1)->pluck('permission_key')->all());
        $this->assertEqualsCanonicalizing(
            ['developer', 'executive'],
            DB::connection('portal')->table('access_presets')->where('permission_key', 'manage.reports.unlisted')->pluck('role')->all(),
        );
        $this->assertSame(['developer'], DB::connection('portal')->table('access_presets')->where('permission_key', 'administration.recycle.unlisted')->pluck('role')->all());
    }

    // Grouping rows scope nothing themselves, so they offer no reach: each page or kind under them has its own.
    public function test_grouping_rows_carry_no_reach(): void
    {
        $reach = DB::connection('portal')->table('access_permissions')->whereIn('permission_key', ['manage', 'manage.requests', 'administration'])->pluck('supports_reach', 'permission_key');

        $this->assertSame(['administration' => 0, 'manage' => 0, 'manage.requests' => 0], $reach->map(static fn ($value): int => (int) $value)->sortKeys()->all());
    }

    // Manage / Requests switches over without changing an admin's queue: every kind and decision, for their department.
    public function test_admins_keep_every_manage_requests_rule_for_their_department(): void
    {
        $catalog = DB::connection('portal')->table('access_permissions')->where('permission_key', 'like', 'manage.requests%')->pluck('supports_reach', 'permission_key');
        $presets = DB::connection('portal')->table('access_presets')->where('role', 'admin')->where('permission_key', 'like', 'manage.requests%')->pluck('reach', 'permission_key');

        $this->assertCount(14, $catalog);
        foreach ($catalog as $key => $supportsReach) {
            $this->assertTrue($presets->has($key), "Admins are missing {$key}.");
            $this->assertSame((bool) $supportsReach ? 'department' : null, $presets[$key]);
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

        // Back to what they hold removes the narrowing. Their own choice, so none of it is logged.
        $logs = DB::connection('portal')->table('logs')->where('employee_id', $admin->id)->where('resource', 'access.reach')->count();
        $this->reach($token, 'all')->assertOk()->assertJsonPath('data.0.key', 'home');
        $this->assertCount($total, $this->tracked($token));
        $this->assertFalse(DB::connection('portal')->table('access_self_reach')->where('employee_id', $admin->id)->exists());
        $this->reach($token, 'own')->assertOk();
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
        foreach (['member', 'admin', 'developer'] as $role) {
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

    // Requests follow their rules from day one without anyone losing anything: every role holds them all.
    public function test_every_role_holds_every_request_rule(): void
    {
        $keys = [
            'requests', 'requests.own', 'requests.own.edit', 'requests.own.cancel', 'requests.own.export',
            'requests.leave', 'requests.overtime', 'requests.offset', 'requests.reimbursement',
        ];
        foreach (['member', 'admin', 'developer'] as $role) {
            $held = DB::connection('portal')->table('access_presets')->where('role', $role)->whereIn('permission_key', $keys)->pluck('permission_key')->all();
            $this->assertEqualsCanonicalizing($keys, $held, "The {$role} preset is missing a request rule.");
        }
    }

    public function test_each_request_route_refuses_once_its_rule_is_off(): void
    {
        $token = $this->login($this->member());
        $this->withToken($token)->getJson('/api/development/portal/requests/leave')->assertOk();

        foreach (['requests.leave', 'requests.own.edit', 'requests.own.cancel'] as $key) {
            $this->switchOff($token, $key);
        }

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [])->assertForbidden();
        $this->withToken($token)->patchJson('/api/development/portal/requests/overtime/1', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/development/portal/requests/offset/1/cancel')->assertForbidden();
        // User Requests still lists every kind, and the other forms still file.
        $this->withToken($token)->getJson('/api/development/portal/requests/leave')->assertOk();
        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [])->assertStatus(422);

        $this->switchOff($token, 'requests.own');
        $this->withToken($token)->getJson('/api/development/portal/requests/leave')->assertForbidden();
        $this->withToken($token)->getJson('/api/development/portal/requests/overtime')->assertOk();

        $this->switchOff($token, 'requests');
        foreach (['leave', 'overtime', 'offset', 'reimbursement'] as $kind) {
            $this->withToken($token)->getJson('/api/development/portal/requests/'.$kind)->assertForbidden();
        }
        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement/receipts', [])->assertForbidden();
    }

    // User follows its rules from day one without anyone losing anything: every role holds them all.
    public function test_every_role_holds_every_user_rule_and_logs_follow_theirs(): void
    {
        $keys = ['user', 'user.logs', 'user.logs.export', 'user.recycle', 'user.recycle.restore', 'user.recycle.export'];
        foreach (['member', 'admin', 'developer'] as $role) {
            $held = DB::connection('portal')->table('access_presets')->where('role', $role)->whereIn('permission_key', $keys)->pluck('permission_key')->all();
            $this->assertEqualsCanonicalizing($keys, $held, "The {$role} preset is missing a User rule.");
        }

        $token = $this->login($this->member());
        $this->withToken($token)->getJson('/api/development/portal/user/logs')->assertOk();
        $this->switchOff($token, 'user.logs');
        $this->withToken($token)->getJson('/api/development/portal/user/logs')->assertForbidden();
        $this->withToken($token)->getJson('/api/development/portal/user/logs/1')->assertForbidden();
    }

    // Event Calendar: everyone sees it and may download it; adding events is admin and above by default.
    public function test_event_calendar_follows_its_rules(): void
    {
        foreach (['member', 'admin', 'executive', 'developer'] as $role) {
            $held = DB::connection('portal')->table('access_presets')->where('role', $role)->whereIn('permission_key', ['calendar', 'calendar.create', 'calendar.download'])->pluck('permission_key')->all();
            $expected = $role === 'member' ? ['calendar', 'calendar.download'] : ['calendar', 'calendar.create', 'calendar.download'];
            $this->assertEqualsCanonicalizing($expected, $held, "The {$role} preset has the wrong Event Calendar rules.");
        }

        $token = $this->login($this->member());
        $this->withToken($token)->getJson('/api/development/portal/calendar/events?from=2026-09-01&to=2026-09-30')->assertOk();
        $this->withToken($token)->getJson('/api/development/portal/calendar/event-options')->assertForbidden();
        $this->withToken($token)->postJson('/api/development/portal/calendar/events', [])->assertForbidden();

        $this->switchOff($token, 'calendar');
        $this->withToken($token)->getJson('/api/development/portal/calendar/events?from=2026-09-01&to=2026-09-30')->assertForbidden();
        // Holidays stay open: the report forms mark them too.
        $this->withToken($token)->getJson('/api/development/portal/calendar/holidays')->assertOk();
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
