<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesPortalAccess;
use Tests\TestCase;

/**
 * Manage / Users follows its rules: the roster and profiles by the Users reach, Change roles and
 * Manage other admins only for executives and MIS admins, the private block by Show employee
 * information, and a person's own defaults (access_personal) counting like their role.
 */
class PortalManageUsersRulesTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private Employee $developer;

    private Employee $misAdmin;

    private Employee $engineeringAdmin;

    private Employee $misMember;

    private Employee $engineeringMember;

    private Employee $executive;

    /**
     * The personal defaults the database was seeded with, read before the test clears them.
     *
     * @var list<string>
     */
    private array $seededDefaults = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->seededDefaults = DB::connection('portal')->table('access_personal')
            ->join('employees', 'employees.id', '=', 'access_personal.employee_id')
            ->orderBy('employees.id_no')->orderBy('access_personal.permission_key')
            ->get(['employees.id_no', 'access_personal.permission_key', 'access_personal.reach'])
            ->map(static fn (object $row): string => $row->id_no.':'.$row->permission_key.':'.($row->reach ?? '-'))->all();
        $this->clearAccessTables();

        [$this->developer, $this->misAdmin, $this->engineeringAdmin] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 3);
        [$this->misMember, $this->engineeringMember] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 2);
        $this->executive = $this->activeWhere("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];
        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->misAdmin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->engineeringAdmin->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        $this->misMember->forceFill(['is_developer' => false, 'department' => 'MIS', 'tax_identification_no' => '123-456-789', 'bank_account_number' => '0001112223'])->save();
        $this->engineeringMember->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        $this->executive->forceFill(['is_developer' => false, 'department' => 'Engineering', 'tax_identification_no' => '999-000-111'])->save();
    }

    // Admins see their own department; executives see everyone; a member sees nothing.
    public function test_the_roster_follows_the_users_reach(): void
    {
        $mis = $this->rosterIds('default', $this->misAdmin);
        $this->assertContains($this->misMember->id, $mis);
        $this->assertNotContains($this->engineeringMember->id, $mis);

        $everyone = $this->rosterIds('default', $this->executive);
        $this->assertContains($this->misMember->id, $everyone);
        $this->assertContains($this->engineeringMember->id, $everyone);

        $this->asMode('default', $this->misMember)->getJson('/api/development/portal/manage/users')->assertForbidden();
    }

    // A member given Users by their admin opens their department's roster, and nothing more.
    public function test_a_member_given_users_opens_their_department(): void
    {
        $this->change('default', $this->misAdmin, $this->misMember, ['key' => 'manage.users', 'action' => 'on', 'reach' => 'department'])->assertOk();

        $ids = $this->rosterIds('default', $this->misMember);

        $this->assertContains($this->misAdmin->id, $ids);
        $this->assertNotContains($this->engineeringMember->id, $ids);
        $this->asMode('default', $this->misMember)->patchJson("/api/development/portal/manage/users/{$this->misAdmin->id}/role", ['role' => 'User'])->assertForbidden();
    }

    // Change roles and Manage other admins stay with executives and MIS admins; for anyone else they are not there at all.
    public function test_change_roles_and_manage_other_admins_are_for_executives_and_mis_admins(): void
    {
        $this->assertNotNull($this->ownPower('default', $this->misAdmin, 'manage.users.role'));
        $this->assertNull($this->ownPower('default', $this->engineeringAdmin, 'manage.users.role'));
        $this->assertNotNull($this->ownPower('default', $this->executive, 'manage.users.role'));

        $this->asMode('default', $this->engineeringAdmin)->patchJson("/api/development/portal/manage/users/{$this->engineeringMember->id}/role", ['role' => 'Admin'])
            ->assertForbidden();
        $this->asMode('default', $this->executive)->patchJson("/api/development/portal/manage/users/{$this->engineeringMember->id}/role", ['role' => 'Admin'])
            ->assertOk();

        $keys = array_column($this->asMode('dev', $this->developer)->getJson("/api/development/portal/manage/access/{$this->engineeringAdmin->id}")->assertOk()->json('data'), 'key');
        $this->assertNotContains('manage.users.access.peers', $keys);
        $this->assertNotContains('manage.users.role', $keys);
        $this->change('dev', $this->developer, $this->engineeringAdmin, ['key' => 'manage.users.access.peers', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'Only executives and MIS administrators can hold that power.');
        $this->change('dev', $this->developer, $this->misAdmin, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();

        // Moving to MIS brings Change roles; moving away takes it and Manage other admins with it.
        $this->engineeringAdmin->forceFill(['department' => 'MIS'])->save();
        $this->assertNotNull($this->ownPower('default', $this->engineeringAdmin, 'manage.users.role'));
        $this->misAdmin->forceFill(['department' => 'Engineering'])->save();
        $this->assertNull($this->ownPower('default', $this->misAdmin, 'manage.users.access.peers'));
    }

    // The reported ask (2026-10-12): an admin activates and deactivates the accounts in their Users reach.
    public function test_an_admin_activates_and_deactivates_accounts_in_their_reach(): void
    {
        $url = fn (Employee $target): string => "/api/development/portal/manage/users/{$target->id}/status";

        $this->asMode('default', $this->misAdmin)->patchJson($url($this->misMember), ['status' => 'Inactive'])->assertOk();
        $this->assertSame('Inactive', $this->misMember->fresh()?->status);
        $this->postJson('/api/development/portal/auth/login', ['id_no' => $this->misMember->id_no])
            ->assertStatus(401)->assertJsonPath('message', 'This account is not active.');
        $this->assertTrue(DB::connection('portal')->table('logs')
            ->where('employee_id', $this->misAdmin->id)->where('record_id', (string) $this->misMember->id)
            ->where('message', 'like', '%deactivated the account of%')->exists());

        $this->asMode('default', $this->misAdmin)->patchJson($url($this->misMember), ['status' => 'Active'])->assertOk();
        $this->assertSame('Active', $this->misMember->fresh()?->status);
        $this->postJson('/api/development/portal/auth/login', ['id_no' => $this->misMember->id_no])->assertOk();

        // A Pending account, such as one promoted before 2026-10-12, is activated the same way.
        $this->misMember->forceFill(['status' => 'Pending'])->save();
        $this->asMode('default', $this->misAdmin)->patchJson($url($this->misMember), ['status' => 'Active'])->assertOk();
        $this->assertSame('Active', $this->misMember->fresh()?->status);

        $this->asMode('default', $this->misAdmin)->patchJson($url($this->misMember), ['status' => 'Pending'])->assertStatus(422);
        // Outside the reach answers as if they did not exist; never themselves or an executive.
        $this->asMode('default', $this->misAdmin)->patchJson($url($this->engineeringMember), ['status' => 'Inactive'])->assertNotFound();
        $this->asMode('default', $this->misAdmin)->patchJson($url($this->misAdmin), ['status' => 'Inactive'])
            ->assertForbidden()->assertJsonPath('message', 'You cannot change the status of your own account.');
        $this->asMode('default', $this->executive)->patchJson($url($this->executive), ['status' => 'Inactive'])->assertForbidden();
        $this->asMode('default', $this->developer)->patchJson($url($this->executive), ['status' => 'Inactive'])->assertNotFound();
        $this->asMode('dev', $this->developer)->patchJson($url($this->executive), ['status' => 'Inactive'])
            ->assertForbidden()->assertJsonPath('message', 'An executive\'s account cannot be activated or deactivated here.');

        // A fellow admin needs Manage other admins, which the roster says row by row.
        $this->asMode('default', $this->misAdmin)->patchJson($url($this->developer), ['status' => 'Inactive'])
            ->assertForbidden()->assertJsonPath('message', 'A fellow administrator\'s account needs Manage other admins.');
        $rows = collect($this->asMode('default', $this->misAdmin)->getJson('/api/development/portal/manage/users?per_page=100')->assertOk()->json('data'));
        $flag = fn (Employee $person): mixed => $rows->first(fn (array $row): bool => (string) $row['id'] === (string) $person->id)['canChangeStatus'] ?? null;
        $this->assertTrue($flag($this->misMember));
        $this->assertFalse($flag($this->developer));
        $this->assertFalse($flag($this->misAdmin));
        $this->asMode('default', $this->executive)->patchJson($url($this->developer), ['status' => 'Inactive'])->assertOk();
        $this->assertSame('Inactive', $this->developer->fresh()?->status);

        // Without the power, nothing changes.
        DB::connection('portal')->table('access_presets')->where('role', 'admin')->where('permission_key', 'manage.users.status')->delete();
        Cache::flush();
        $this->asMode('default', $this->misAdmin)->patchJson($url($this->misMember), ['status' => 'Inactive'])->assertForbidden();
    }

    // Someone outside the Users reach cannot be changed or opened, answering as if they did not exist.
    public function test_people_outside_the_reach_are_not_found(): void
    {
        $this->asMode('default', $this->misAdmin)->patchJson("/api/development/portal/manage/users/{$this->engineeringMember->id}/role", ['role' => 'Admin'])
            ->assertNotFound();
        $this->asMode('default', $this->misAdmin)->getJson("/api/development/portal/manage/users/{$this->engineeringMember->id}")->assertNotFound();
    }

    // A profile shows who someone is; the private block comes only with Show employee information reaching them.
    public function test_a_profile_shows_private_information_only_with_the_power(): void
    {
        $profile = $this->asMode('default', $this->misAdmin)->getJson("/api/development/portal/manage/users/{$this->misMember->id}")->assertOk();
        $profile->assertJsonPath('data.id', (string) $this->misMember->id)->assertJsonPath('data.information', null);
        $this->assertArrayHasKey('photoUrl', $profile->json('data'));
        $this->assertNull($this->ownPower('default', $this->misAdmin, 'manage.users.information'));

        $this->change('dev', $this->developer, $this->misAdmin, ['key' => 'manage.users.information', 'action' => 'on', 'reach' => 'department'])->assertOk();

        $this->asMode('default', $this->misAdmin)->getJson("/api/development/portal/manage/users/{$this->misMember->id}")->assertOk()
            ->assertJsonPath('data.information.taxIdentificationNo', '123-456-789')
            ->assertJsonPath('data.information.bankAccountNumber', '0001112223');
        $this->assertSame(
            '{UserName|You} viewed the employee information of '.$this->displayName($this->misMember),
            DB::connection('portal')->table('logs')->where('employee_id', $this->misAdmin->id)->where('resource', 'manage.users.information')->orderByDesc('id')->value('message'),
        );
    }

    // Members never hold Show employee information, and an executive's private block is for executives and Developer mode only.
    public function test_private_information_is_for_admins_and_executives_details_for_executives(): void
    {
        $this->change('default', $this->misAdmin, $this->misMember, ['key' => 'manage.users', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->change('default', $this->misAdmin, $this->misMember, ['key' => 'manage.users.information', 'action' => 'on'])->assertNotFound();
        $this->change('dev', $this->developer, $this->misMember, ['key' => 'manage.users.information', 'action' => 'on', 'reach' => 'all'])
            ->assertStatus(422)->assertJsonPath('message', 'Only an administrator or above can hold that power.');

        $this->change('dev', $this->developer, $this->engineeringAdmin, ['key' => 'manage.users.information', 'action' => 'on', 'reach' => 'all'])->assertOk();
        $this->change('dev', $this->developer, $this->engineeringAdmin, ['key' => 'manage.users', 'action' => 'reach', 'reach' => 'all'])->assertOk();
        $this->asMode('default', $this->engineeringAdmin)->getJson("/api/development/portal/manage/users/{$this->executive->id}")->assertOk()
            ->assertJsonPath('data.information', null);
        $this->asMode('default', $this->executive)->getJson("/api/development/portal/manage/users/{$this->executive->id}")->assertOk()
            ->assertJsonPath('data.information.taxIdentificationNo', '999-000-111');
        $this->asMode('dev', $this->developer)->getJson("/api/development/portal/manage/users/{$this->executive->id}")->assertOk()
            ->assertJsonPath('data.information.taxIdentificationNo', '999-000-111');
    }

    // A person's own defaults count like their role: held from the start, named as theirs, and a limit still takes them away.
    public function test_personal_defaults_count_like_the_role(): void
    {
        DB::connection('portal')->table('access_personal')->insert([
            ['employee_id' => $this->misAdmin->id, 'permission_key' => 'manage.users.access.peers', 'reach' => null],
            ['employee_id' => $this->misAdmin->id, 'permission_key' => 'manage.users.information', 'reach' => 'all'],
            ['employee_id' => $this->engineeringAdmin->id, 'permission_key' => 'manage.users.access.peers', 'reach' => null],
        ]);

        $information = $this->ownPower('default', $this->misAdmin, 'manage.users.information');
        $this->assertSame('all', $information['reach'] ?? null);
        $this->assertTrue($information['fromDefault'] ?? false);
        $this->assertTrue($this->editable('default', $this->misAdmin)[$this->developer->id]);
        $this->assertNull($this->ownPower('default', $this->engineeringAdmin, 'manage.users.access.peers'));
        $this->assertTrue($this->sheetRule('dev', $this->developer, $this->misAdmin, 'manage.users.information')['fromDefault']);

        $this->change('dev', $this->developer, $this->misAdmin, ['key' => 'manage.users.information', 'action' => 'off'])->assertOk();

        $this->assertNull($this->ownPower('default', $this->misAdmin, 'manage.users.information'));
    }

    // The seeded defaults: Fin and Audie approve the projects they lead, under the Manage group they hold
    // for it. What Nathaniel and Steven held here is the department-head presets now (2026-10-14, user).
    public function test_the_seeded_personal_defaults_name_fin_and_audie(): void
    {
        $this->assertSame([
            '220825-0004:manage:-',
            '220825-0004:projects.manager:lead',
            '230103-0005:manage:-',
            '230103-0005:projects.manager:lead',
        ], $this->seededDefaults);
    }

    /**
     * @return list<int>
     */
    private function rosterIds(string $mode, Employee $viewer): array
    {
        return array_map('intval', $this->asMode($mode, $viewer)->getJson('/api/development/portal/manage/users?per_page=100')->assertOk()->json('data.*.id'));
    }

    private function displayName(Employee $employee): string
    {
        return trim(trim((string) $employee->first_name).' '.trim((string) $employee->last_name));
    }
}
