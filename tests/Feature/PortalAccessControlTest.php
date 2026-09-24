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

class PortalAccessControlTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private Employee $developer;

    private Employee $admin;

    private Employee $fellow;

    private Employee $member;

    private Employee $outsider;

    private Employee $executive;

    /**
     * @var array<int, string>
     */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();

        [$this->developer, $this->admin, $this->fellow] = $this->active("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 3);
        [$this->member, $this->outsider] = $this->active("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 2);
        $this->executive = $this->active("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];

        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->fellow->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->member->forceFill(['is_developer' => false, 'department' => ' mis '])->save();
        $this->outsider->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        $this->executive->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        foreach (['access_grants', 'access_limits', 'access_toggles'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
    }

    public function test_catalog_lists_access_rules_under_manage(): void
    {
        $manage = collect($this->getJson('/api/development/portal')->assertOk()->json('sections'))->firstWhere('name', 'manage');

        $this->assertContains('access', array_column($manage['resources'], 'name'));
    }

    // The roster tells each viewer whose Access Rules they may open.
    public function test_the_roster_marks_who_each_viewer_may_manage(): void
    {
        $asAdmin = $this->editable($this->admin);
        $this->assertTrue($asAdmin[$this->member->id]);
        $this->assertFalse($asAdmin[$this->outsider->id]);
        $this->assertFalse($asAdmin[$this->admin->id]);
        $this->assertFalse($asAdmin[$this->developer->id]);
        $this->assertFalse($asAdmin[$this->executive->id]);

        $asDeveloper = $this->editable($this->developer);
        foreach ([$this->admin, $this->member, $this->outsider, $this->executive] as $person) {
            $this->assertTrue($asDeveloper[$person->id]);
        }
        $this->assertFalse($asDeveloper[$this->developer->id]);
    }

    public function test_only_people_the_editor_manages_open(): void
    {
        $this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->outsider->id}")->assertNotFound();
        $this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->developer->id}")->assertNotFound();
        $this->as($this->member)->getJson("/api/development/portal/manage/access/{$this->outsider->id}")->assertForbidden();
    }

    public function test_a_person_shows_every_power_the_editor_holds_with_what_they_keep_without_it(): void
    {
        $response = $this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}")
            ->assertOk()
            ->assertJsonPath('employee.id', (string) $this->member->id)
            ->assertJsonPath('employee.tier', 'member');

        $daily = $this->rule($response, 'reports.daily');
        $this->assertTrue($daily['holds']);
        $this->assertTrue($daily['fromRole']);
        $this->assertTrue($daily['heldWithoutYou']);
        $requests = $this->rule($response, 'manage.requests');
        $this->assertFalse($requests['holds']);
        $this->assertFalse($requests['heldWithoutYou']);
        $this->assertSame('department', $requests['yourReach']);
        $this->assertNotContains('administration.backup', array_column($response->json('data'), 'key'));
    }

    // Switching a tab on brings the pages above it and the tabs under it.
    public function test_switching_a_tab_on_gives_it_with_its_pages_and_shows_on_their_access_page(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])->assertOk();

        $this->assertSame(4, DB::connection('portal')->table('access_grants')->where('employee_id', $this->member->id)->count());
        $leave = $this->power($this->member, 'manage.requests.leave');
        $this->assertIsArray($leave);
        $this->assertFalse($leave['fromRole']);
        $this->assertSame('department', $leave['reach']);
        $this->assertNotNull($this->power($this->member, 'manage.requests.leave.decide'));
        $this->assertNull($this->power($this->member, 'manage.requests.overtime'));
        $this->assertSame(
            '{UserName|You} switched on Manage / Requests / Leave Requests for '.$this->displayName($this->member),
            PortalLog::query()->where('employee_id', $this->admin->id)->where('resource', 'manage.access')->value('message'),
        );
    }

    public function test_a_switch_is_refused_when_too_wide_not_held_or_unknown(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'manage.requests', 'action' => 'on', 'reach' => 'all'])
            ->assertStatus(422)->assertJsonPath('message', 'You cannot give a power wider than you hold it.');
        $this->change($this->admin, $this->member, ['key' => 'manage.requests', 'action' => 'on', 'includeExecutives' => true])
            ->assertStatus(422);
        $this->change($this->admin, $this->member, ['key' => 'administration.backup', 'action' => 'on'])->assertNotFound();
        $this->change($this->admin, $this->member, ['key' => 'home', 'action' => 'give'])
            ->assertStatus(422)->assertJsonValidationErrors('action');
        $this->assertSame(0, DB::connection('portal')->table('access_grants')->count());
    }

    // A power from their role cannot be taken back, so switching it off limits it; on lifts the limit.
    public function test_switching_off_a_role_power_limits_it_and_switching_on_lifts_the_limit(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'reports.submitted', 'action' => 'off'])->assertOk();
        $this->assertNull($this->power($this->member, 'reports.submitted'));
        $this->assertNull($this->power($this->member, 'reports.submitted.edit'));
        $this->assertNotNull($this->power($this->member, 'reports.daily'));
        $this->assertTrue($this->rule($this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}"), 'reports.submitted')['limitedByYou']);

        $this->change($this->admin, $this->member, ['key' => 'reports.submitted.edit', 'action' => 'on'])->assertOk();

        $this->assertNotNull($this->power($this->member, 'reports.submitted.edit'));
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
        $this->assertSame(0, DB::connection('portal')->table('access_grants')->count());
    }

    // A power only the editor gave is simply taken back; no limit is left behind.
    public function test_switching_off_your_own_gift_takes_it_back(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->change($this->admin, $this->member, ['key' => 'calendar.create', 'action' => 'off'])->assertOk();

        $this->assertNull($this->power($this->member, 'calendar.create'));
        $this->assertSame(0, DB::connection('portal')->table('access_grants')->count());
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // What someone gave lives only as long as they hold it: lose it and it goes, regain it and it returns.
    public function test_losing_a_power_ends_what_was_given_from_it_and_regaining_restores_it(): void
    {
        $this->change($this->developer, $this->admin, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->change($this->admin, $this->member, ['key' => 'administration.backup.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->member, 'administration.backup.create'));

        $this->change($this->developer, $this->admin, ['key' => 'administration.backup', 'action' => 'off'])->assertOk();
        $this->assertNull($this->power($this->admin, 'administration.backup'));
        $this->assertNull($this->power($this->member, 'administration.backup.create'));

        $this->change($this->developer, $this->admin, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->member, 'administration.backup.create'));
    }

    // Someone else's limit is refused by name; a developer lifts anyone's.
    public function test_another_editors_limit_blocks_switching_on_until_a_developer_lifts_it(): void
    {
        $this->change($this->developer, $this->member, ['key' => 'reports.late', 'action' => 'off'])->assertOk();

        $this->change($this->admin, $this->member, ['key' => 'reports.late', 'action' => 'on'])
            ->assertStatus(422)
            ->assertJsonPath('message', $this->displayName($this->developer).' limited that power, so it cannot be switched on.');
        $shown = $this->rule($this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}"), 'reports.late');
        $this->assertSame([$this->displayName($this->developer)], $shown['limitedBy']);

        $this->change($this->admin, $this->member, ['key' => 'reports.late', 'action' => 'off'])->assertOk();
        $this->change($this->developer, $this->member, ['key' => 'reports.late', 'action' => 'on'])->assertOk();

        $this->assertNotNull($this->power($this->member, 'reports.late'));
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // A gift never reaches further than its giver does right now.
    public function test_changing_reach_reshapes_the_gift_and_narrows_what_was_given_from_it(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'manage.requests', 'action' => 'reach', 'reach' => 'own'])
            ->assertStatus(422)->assertJsonPath('message', 'Switch the power on before choosing how far it reaches.');

        $this->change($this->developer, $this->admin, ['key' => 'manage.requests', 'action' => 'reach', 'reach' => 'all'])->assertOk();
        $this->change($this->admin, $this->member, ['key' => 'manage.requests', 'action' => 'on', 'reach' => 'own'])->assertOk();
        $this->change($this->admin, $this->member, ['key' => 'manage.requests', 'action' => 'reach', 'reach' => 'all'])->assertOk();
        $this->assertSame('all', $this->power($this->member, 'manage.requests')['reach'] ?? null);

        $this->change($this->developer, $this->admin, ['key' => 'manage.requests', 'action' => 'reach', 'reach' => 'department'])->assertOk();

        $this->assertSame('department', $this->power($this->member, 'manage.requests')['reach'] ?? null);
        $this->assertSame('department', $this->rule($this->as($this->developer)->getJson("/api/development/portal/manage/access/{$this->admin->id}"), 'manage.requests')['reachWithoutYou']);
    }

    // Moving the member out of the admin's department ends the admin's hold over them.
    public function test_a_gift_stops_counting_once_the_giver_no_longer_covers_them(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->member, 'calendar.create'));

        $this->member->forceFill(['department' => 'Engineering'])->save();

        $this->assertNull($this->power($this->member, 'calendar.create'));
        $this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}")->assertNotFound();
    }

    // An admin manages fellow admins only once someone above gives them Manage other admins.
    public function test_manage_other_admins_lets_an_admin_manage_a_fellow_admin(): void
    {
        $this->assertFalse($this->editable($this->admin)[$this->fellow->id]);
        $this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->fellow->id}")->assertNotFound();

        $this->change($this->developer, $this->admin, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();

        $this->assertTrue($this->editable($this->admin)[$this->fellow->id]);
        $this->change($this->admin, $this->fellow, ['key' => 'manage.requests', 'action' => 'off'])->assertOk();
        $this->assertNull($this->power($this->fellow, 'manage.requests'));

        // Taken away from above, the admin's hold over the fellow admin ends, and so does the limit.
        $this->change($this->developer, $this->admin, ['key' => 'manage.users.access.peers', 'action' => 'off'])->assertOk();
        $this->assertFalse($this->editable($this->admin)[$this->fellow->id]);
        $this->assertNotNull($this->power($this->fellow, 'manage.requests'));
    }

    public function test_manage_other_admins_is_for_administrators_only(): void
    {
        $this->change($this->developer, $this->admin, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();

        $this->change($this->admin, $this->member, ['key' => 'manage.users.access.peers', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'Only an administrator or above can hold that power.');
        $this->assertFalse($this->rule($this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}"), 'manage.users.access.peers')['canHold']);

        // Switching the whole section on for a member brings everything but the admins-only rule.
        $this->change($this->admin, $this->member, ['key' => 'manage.users.access', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->assertNotNull($this->power($this->member, 'manage.users.access.give'));
        $this->assertNull($this->power($this->member, 'manage.users.access.peers'));
        $this->change($this->admin, $this->fellow, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->fellow, 'manage.users.access.peers'));
    }

    // Between equals only role and what came from above counts, so two admins cannot prop each other up.
    public function test_an_admin_passes_a_fellow_admin_only_what_came_from_their_role_or_above(): void
    {
        foreach ([$this->admin, $this->fellow] as $admin) {
            $this->change($this->developer, $admin, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();
        }
        $this->change($this->developer, $this->fellow, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->change($this->fellow, $this->admin, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->admin, 'administration.backup'));

        $this->change($this->admin, $this->fellow, ['key' => 'administration.backup.create', 'action' => 'off'])
            ->assertNotFound()->assertJsonPath('message', 'You do not hold that power.');
        $this->change($this->admin, $this->member, ['key' => 'administration.backup.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->member, 'administration.backup.create'));
    }

    public function test_executives_and_developers_hold_manage_other_admins_and_admins_do_not(): void
    {
        $this->assertNotNull($this->power($this->executive, 'manage.users.access.peers'));
        $this->assertNotNull($this->power($this->developer, 'manage.users.access.peers'));
        $this->assertNull($this->power($this->admin, 'manage.users.access.peers'));
    }

    private function change(Employee $editor, Employee $target, array $body): TestResponse
    {
        return $this->as($editor)->patchJson("/api/development/portal/manage/access/{$target->id}", $body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function power(Employee $employee, string $key): ?array
    {
        return collect($this->as($employee)->getJson('/api/development/portal/access')->assertOk()->json('data'))->firstWhere('key', $key);
    }

    /**
     * @return array<int, bool>
     */
    private function editable(Employee $viewer): array
    {
        $rows = $this->as($viewer)->getJson('/api/development/portal/manage/users?per_page=100')->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $row): array => [(int) $row['id'] => $row['canEditAccess']])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(TestResponse $response, string $key): array
    {
        $rule = collect($response->assertOk()->json('data'))->firstWhere('key', $key);
        $this->assertIsArray($rule, "Rule {$key} is missing.");

        return $rule;
    }

    private function displayName(Employee $employee): string
    {
        return trim(trim((string) $employee->first_name).' '.trim((string) $employee->last_name));
    }

    // The developer fixture works in Developer mode; everyone else as their role.
    private function as(Employee $employee): self
    {
        if (! isset($this->tokens[$employee->id])) {
            $token = (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])
                ->assertOk()
                ->json('token');
            $this->tokens[$employee->id] = (bool) $employee->is_developer ? $this->developerModeOn($token) : $token;
        }

        return $this->withToken($this->tokens[$employee->id]);
    }

    /**
     * @return list<Employee>
     */
    private function active(string $where, int $count): array
    {
        $employees = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->all();
        $this->assertCount($count, $employees);

        return $employees;
    }
}
