<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
