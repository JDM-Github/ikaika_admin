<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PortalAccessTest extends TestCase
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

        // Presets alone: grants made while testing by hand must not change what a role starts with.
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
    }

    public function test_catalog_lists_access_under_portal_sections(): void
    {
        $names = array_column($this->getJson('/api/development/portal')->assertOk()->json('sections'), 'name');

        $this->assertContains('access', $names);
    }

    public function test_the_access_page_requires_a_session(): void
    {
        $this->getJson('/api/development/portal/access')->assertUnauthorized();
        $this->patchJson('/api/development/portal/access/switches', ['key' => 'home', 'on' => false])->assertUnauthorized();
    }

    public function test_a_member_holds_only_their_own_work(): void
    {
        $response = $this->powersOf($this->member())->assertOk()
            ->assertJsonPath('section', 'access')
            ->assertJsonPath('resource', 'powers')
            ->assertJsonPath('tier', 'member');

        $keys = $this->keys($response);
        $this->assertContains('reports.daily', $keys);
        $this->assertContains('access', $keys);
        $this->assertNotContains('manage', $keys);
        $this->assertNotContains('calendar.create', $keys);
        $this->assertNotContains('developer', $keys);
        $this->assertSame('own', $this->rule($response, 'projects.view')['reach']);
        $this->assertFalse($this->rule($response, 'access')['canToggle']);
        $this->assertNull($this->rule($response, 'home')['parentKey']);
        $this->assertSame('reports', $this->rule($response, 'reports.daily')['parentKey']);
    }

    // Project admin is retired: someone still carrying the role starts as a plain member.
    public function test_a_project_admin_starts_as_a_member(): void
    {
        $member = $this->member();
        $member->forceFill(['role' => 'ProjectAdmin'])->save();

        $this->powersOf($member)->assertOk()->assertJsonPath('tier', 'member');
    }

    public function test_an_admin_holds_their_department_but_no_developer_tools(): void
    {
        $response = $this->powersOf($this->admin())->assertOk()->assertJsonPath('tier', 'admin');

        $keys = $this->keys($response);
        $this->assertContains('manage.requests.leave.decide', $keys);
        $this->assertContains('calendar.create', $keys);
        $this->assertNotContains('developer', $keys);
        $this->assertNotContains('administration.backup', $keys);
        $this->assertNotContains('administration.actions.sync', $keys);
        $this->assertSame('department', $this->rule($response, 'manage.requests.leave')['reach']);
        $this->assertSame('all', $this->rule($response, 'projects.view')['reach']);
        $this->assertFalse($this->rule($response, 'administration.logs')['includeExecutives']);
    }

    public function test_an_executive_also_sees_executives_logs(): void
    {
        $response = $this->powersOf($this->executive())->assertOk()->assertJsonPath('tier', 'executive');

        $this->assertTrue($this->rule($response, 'administration.logs')['includeExecutives']);
        $this->assertSame('all', $this->rule($response, 'manage.requests.leave')['reach']);
    }

    // is_developer alone grants nothing: in Default a developer holds their role's preset.
    public function test_a_developer_holds_every_rule_for_every_department_only_in_developer_mode(): void
    {
        $token = $this->loginToken($this->admin(developer: true));
        $default = $this->withToken($token)->getJson('/api/development/portal/access')->assertOk()->assertJsonPath('tier', 'admin');
        $this->assertNotContains('developer.flag', $this->keys($default));

        $response = $this->withToken($this->developerModeOn($token))->getJson('/api/development/portal/access')
            ->assertOk()->assertJsonPath('tier', 'developer');

        $this->assertCount(DB::connection('portal')->table('access_permissions')->count(), $response->json('data'));
        $this->assertSame('all', $this->rule($response, 'manage.requests.leave')['reach']);
        $this->assertNull($this->rule($response, 'developer.flag')['reach']);
    }

    // View Projects parity (2026-10-01): each role's project powers and reaches, so a preset change is a decision, not an accident.
    public function test_project_presets_keep_what_each_role_could_do(): void
    {
        $presets = [];
        foreach (DB::connection('portal')->table('access_presets')->where('permission_key', 'like', 'projects%')->get() as $row) {
            $presets[$row->role][$row->permission_key] = $row->reach;
        }
        $everything = [
            'projects' => null, 'projects.view' => 'all', 'projects.edit' => 'all', 'projects.scopes' => 'all',
            'projects.delete' => 'all', 'projects.lead' => 'all', 'projects.create' => 'all', 'projects.create.direct' => null,
            'projects.manager' => 'all', 'projects.track' => 'all', 'projects.track.export' => null,
        ];
        // Project Settings is Developer mode only, so only the developer preset names it. Admins lead
        // only their own department; Nathaniel and Steven are given lead at all as a personal default.
        $developer = [...$everything, 'projects.settings' => null];
        $admin = $everything;
        $admin['projects.lead'] = 'department';
        ksort($everything);
        ksort($developer);
        ksort($admin);
        foreach ($presets as &$rules) {
            ksort($rules);
        }

        $this->assertSame($admin, $presets['admin']);
        $this->assertSame($everything, $presets['executive']);
        $this->assertSame($developer, $presets['developer']);
        // Anyone may add a project (2026-10-06); a member's waits for a Project Manager.
        $this->assertSame([
            'projects' => null, 'projects.create' => 'all', 'projects.track' => 'own', 'projects.track.export' => null, 'projects.view' => 'own',
        ], $presets['member']);
    }

    // A preset that held a child without its parent would leave that child with nowhere to hang.
    public function test_every_preset_holds_the_parent_of_each_rule_it_holds(): void
    {
        $orphans = DB::connection('portal')->table('access_presets as presets')
            ->join('access_permissions as permissions', 'permissions.permission_key', '=', 'presets.permission_key')
            ->leftJoin('access_presets as parents', function ($join): void {
                $join->on('parents.permission_key', '=', 'permissions.parent_key')
                    ->on('parents.role', '=', 'presets.role');
            })
            ->whereNotNull('permissions.parent_key')
            ->whereNull('parents.role')
            ->pluck('presets.permission_key')
            ->all();

        $this->assertSame([], $orphans);
    }

    // Their own choice about powers they already hold, so it is never logged.
    public function test_switching_a_parent_off_turns_its_children_off_and_is_not_logged(): void
    {
        $member = $this->member();
        $token = $this->loginToken($member);
        $startedAt = now()->subSecond();

        $off = $this->withToken($token)
            ->patchJson('/api/development/portal/access/switches', ['key' => 'reports', 'on' => false])
            ->assertOk();

        $this->assertFalse($this->rule($off, 'reports')['isSwitchedOn']);
        $this->assertFalse($this->rule($off, 'reports')['isActive']);
        $this->assertTrue($this->rule($off, 'reports.submitted.edit')['isSwitchedOn']);
        $this->assertFalse($this->rule($off, 'reports.submitted.edit')['isActive']);
        $this->assertTrue($this->rule($off, 'requests')['isActive']);

        $on = $this->withToken($token)
            ->patchJson('/api/development/portal/access/switches', ['key' => 'reports', 'on' => true])
            ->assertOk();
        $this->assertTrue($this->rule($on, 'reports.submitted.edit')['isActive']);
        $this->assertSame(0, PortalLog::query()->where('employee_id', $member->getKey())->where('created_at', '>=', $startedAt)->where('resource', 'like', 'access.%')->count());
    }

    public function test_a_switch_is_refused_for_a_power_not_held_or_always_on(): void
    {
        $token = $this->loginToken($this->member());

        $this->withToken($token)
            ->patchJson('/api/development/portal/access/switches', ['key' => 'manage.requests', 'on' => false])
            ->assertNotFound();
        $this->withToken($token)
            ->patchJson('/api/development/portal/access/switches', ['key' => 'access', 'on' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That power always stays on.');
        $this->withToken($token)
            ->patchJson('/api/development/portal/access/switches', ['key' => 'home'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('on');
    }

    private function powersOf(Employee $employee): TestResponse
    {
        return $this->withToken($this->loginToken($employee))->getJson('/api/development/portal/access');
    }

    /**
     * @return list<string>
     */
    private function keys(TestResponse $response): array
    {
        return array_column($response->json('data'), 'key');
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(TestResponse $response, string $key): array
    {
        $rule = collect($response->json('data'))->firstWhere('key', $key);
        $this->assertIsArray($rule, "Rule {$key} is missing.");

        return $rule;
    }

    private function member(): Employee
    {
        return $this->active("LOWER(COALESCE(role, '')) = 'user'", false);
    }

    private function admin(bool $developer = false): Employee
    {
        return $this->active("LOWER(COALESCE(role, '')) = 'admin'", $developer);
    }

    private function executive(): Employee
    {
        return $this->active("LOWER(COALESCE(role_level, '')) = 'executive'", false, executive: true);
    }

    private function active(string $where, bool $developer, bool $executive = false): Employee
    {
        $query = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '');
        if (! $executive) {
            $query->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'");
        }
        $employee = $query->first();
        $this->assertNotNull($employee);
        $employee->forceFill(['is_developer' => $developer])->save();

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])
            ->assertOk()
            ->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
