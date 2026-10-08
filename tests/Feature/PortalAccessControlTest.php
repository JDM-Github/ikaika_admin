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

        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'access_lists'] as $table) {
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
        // Outside the admin's department they are not on the roster at all.
        $this->assertArrayNotHasKey($this->outsider->id, $asAdmin);
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
        $requests = $this->rule($response, 'manage.requests.leave');
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
            PortalLog::query()->where('employee_id', $this->admin->id)->where('resource', 'manage.access')->orderByDesc('id')->value('message'),
        );
    }

    public function test_a_switch_is_refused_when_too_wide_not_held_or_unknown(): void
    {
        $this->useBackupAsAPlainPower();
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'all'])
            ->assertStatus(422)->assertJsonPath('message', 'You cannot give a power wider than you hold it.');
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'includeExecutives' => true])
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
        $this->useBackupAsAPlainPower();
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

        // Outside Developer mode a developer's act reads as a developer's, never by name.
        $this->change($this->admin, $this->member, ['key' => 'reports.late', 'action' => 'on'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A developer limited that power, so it cannot be switched on.');
        $shown = $this->rule($this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}"), 'reports.late');
        $this->assertSame(['a developer'], $shown['limitedBy']);
        $this->assertTrue($shown['lockedByOthers']);
        $this->assertFalse($shown['canOverride']);

        $this->change($this->admin, $this->member, ['key' => 'reports.late', 'action' => 'off'])->assertOk();
        $this->change($this->developer, $this->member, ['key' => 'reports.late', 'action' => 'on'])->assertOk();

        $this->assertNotNull($this->power($this->member, 'reports.late'));
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // A gift never reaches further than its giver does right now.
    public function test_changing_reach_reshapes_the_gift_and_narrows_what_was_given_from_it(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'reach', 'reach' => 'own'])
            ->assertStatus(422)->assertJsonPath('message', 'Switch the power on before choosing how far it reaches.');

        $this->change($this->developer, $this->admin, ['key' => 'manage.requests.leave', 'action' => 'reach', 'reach' => 'all'])->assertOk();
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'own'])->assertOk();
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'reach', 'reach' => 'all'])->assertOk();
        $this->assertSame('all', $this->power($this->member, 'manage.requests.leave')['reach'] ?? null);

        $this->change($this->developer, $this->admin, ['key' => 'manage.requests.leave', 'action' => 'reach', 'reach' => 'department'])->assertOk();

        $this->assertSame('department', $this->power($this->member, 'manage.requests.leave')['reach'] ?? null);
        $this->assertSame('department', $this->rule($this->as($this->developer)->getJson("/api/development/portal/manage/access/{$this->admin->id}"), 'manage.requests.leave')['reachWithoutYou']);
    }

    // "The projects you lead" reaches the three project rows and nothing else.
    public function test_the_lead_reach_belongs_only_to_the_project_rows(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'lead'])
            ->assertStatus(422);

        $this->change($this->admin, $this->member, ['key' => 'projects.edit', 'action' => 'on', 'reach' => 'lead'])->assertOk();
        $this->assertSame('lead', $this->power($this->member, 'projects.edit')['reach'] ?? null);
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
        // A power their role can never hold is not on their sheet at all.
        $keys = array_column($this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}")->assertOk()->json('data'), 'key');
        $this->assertNotContains('manage.users.access.peers', $keys);

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
        $this->useBackupAsAPlainPower();
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

    // Developer tools and All Backups come only with Developer mode: the sheet never offers them, and nobody, a developer included, can give or limit them.
    public function test_developer_only_powers_can_never_be_given_or_limited(): void
    {
        $keys = array_column($this->as($this->developer)->getJson("/api/development/portal/manage/access/{$this->admin->id}")->assertOk()->json('data'), 'key');
        $this->assertContains('manage.requests', $keys);
        foreach ($keys as $key) {
            $this->assertStringStartsNotWith('developer', $key);
            $this->assertStringStartsNotWith('administration.backup', $key);
        }

        foreach (['developer', 'developer.rules', 'administration.backup', 'administration.backup.restore'] as $key) {
            foreach (['on', 'off'] as $action) {
                $this->change($this->developer, $this->admin, ['key' => $key, 'action' => $action])
                    ->assertStatus(422)
                    ->assertJsonPath('message', 'That power comes only with Developer mode, so nobody can give or limit it.');
            }
        }
        $this->assertSame(0, DB::connection('portal')->table('access_grants')->count());
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // A gift of a developer tool written straight into the table still gives nothing, even from a developer.
    public function test_a_stored_gift_of_a_developer_tool_counts_for_nobody(): void
    {
        DB::connection('portal')->table('access_grants')->insert([
            ['employee_id' => $this->admin->id, 'permission_key' => 'developer', 'granted_by' => $this->developer->id],
            ['employee_id' => $this->admin->id, 'permission_key' => 'developer.rules', 'granted_by' => $this->developer->id],
            ['employee_id' => $this->admin->id, 'permission_key' => 'administration.backup', 'granted_by' => $this->developer->id],
        ]);

        $this->assertNull($this->power($this->admin, 'developer'));
        $this->assertNull($this->power($this->admin, 'developer.rules'));
        $this->assertNull($this->power($this->admin, 'administration.backup'));
        $this->assertNotNull($this->power($this->developer, 'developer.rules'));
        $this->assertNotNull($this->power($this->developer, 'administration.backup'));
    }

    // A black list hides the people on it from that person's Reports, and the Access page says who set it.
    public function test_a_black_list_hides_people_from_reports(): void
    {
        $this->list($this->developer, $this->admin, 'block', [$this->member])->assertOk();

        $this->assertNotContains((string) $this->member->id, $this->roster($this->admin));
        $this->assertContains((string) $this->fellow->id, $this->roster($this->admin));
        $this->as($this->admin)->getJson("/api/development/portal/manage/reports?employee_id={$this->member->id}")->assertNotFound();
        $this->assertSame([['name' => 'a developer', 'mode' => 'block']], $this->power($this->admin, 'manage.reports')['lists'] ?? null);
        $this->assertSame(
            '{UserName|You} set a black list of 1 person on Manage / Reports for '.$this->displayName($this->admin),
            PortalLog::query()->where('employee_id', $this->developer->id)->where('resource', 'manage.access')->orderByDesc('id')->value('message'),
        );
    }

    // A white list shows only the people it names; the viewer always keeps their own timesheets.
    public function test_a_white_list_shows_only_the_people_it_names(): void
    {
        $this->list($this->developer, $this->admin, 'allow', [$this->fellow])->assertOk();

        $this->assertEqualsCanonicalizing([(string) $this->admin->id, (string) $this->fellow->id], $this->roster($this->admin));
    }

    // Every maker's list counts at once, and Ignore white and black lists skips them all.
    public function test_every_list_counts_until_the_person_ignores_lists(): void
    {
        $this->list($this->developer, $this->admin, 'allow', [$this->fellow, $this->member])->assertOk();
        $this->list($this->executive, $this->admin, 'block', [$this->fellow])->assertOk();

        $this->assertEqualsCanonicalizing([(string) $this->admin->id, (string) $this->member->id], $this->roster($this->admin));
        $sheet = $this->rule($this->as($this->developer)->getJson("/api/development/portal/manage/access/{$this->admin->id}"), 'manage.reports');
        $this->assertSame('allow', $sheet['list']['mode']);
        $this->assertEqualsCanonicalizing([(string) $this->fellow->id, (string) $this->member->id], $sheet['list']['people']);
        $this->assertSame([['name' => $this->displayName($this->executive), 'mode' => 'block']], $sheet['list']['others']);
        $this->assertFalse($sheet['list']['ignores']);

        $this->change($this->developer, $this->admin, ['key' => 'manage.reports.unlisted', 'action' => 'on'])->assertOk();

        $this->assertContains((string) $this->fellow->id, $this->roster($this->admin));
        $this->assertTrue($this->power($this->admin, 'manage.reports')['ignoresLists']);
    }

    // Admins start without Ignore white and black lists; executives and developers hold it.
    public function test_only_executives_and_developers_ignore_lists_by_default(): void
    {
        $this->assertNull($this->power($this->admin, 'manage.reports.unlisted'));
        $this->assertNotNull($this->power($this->executive, 'manage.reports.unlisted'));
        $this->assertNotNull($this->power($this->developer, 'manage.reports.unlisted'));
    }

    // The editor lists only people they can see themselves, themselves included, and a blocked person stays out of their picker.
    public function test_an_editor_lists_only_people_they_can_see(): void
    {
        $this->change($this->admin, $this->member, ['key' => 'manage.reports', 'action' => 'list', 'mode' => 'block', 'people' => [$this->fellow->id]])
            ->assertStatus(422)->assertJsonPath('message', 'Switch the power on before choosing who it shows.');
        $this->change($this->admin, $this->member, ['key' => 'manage.reports', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->list($this->developer, $this->admin, 'block', [$this->fellow])->assertOk();

        $picker = collect($this->as($this->admin)->getJson("/api/development/portal/manage/access/{$this->member->id}")->assertOk()->json('listPeople')['manage.reports'] ?? [])->pluck('id')->all();
        $this->assertContains((string) $this->admin->id, $picker);
        $this->assertNotContains((string) $this->fellow->id, $picker);
        $this->assertNotContains((string) $this->outsider->id, $picker);

        foreach ([$this->fellow, $this->outsider] as $hidden) {
            $this->list($this->admin, $this->member, 'block', [$hidden])
                ->assertStatus(422)->assertJsonPath('message', 'You can only list people you can see yourself.');
        }
        $this->list($this->admin, $this->member, 'block', [$this->admin])->assertOk();
        $this->assertNotContains((string) $this->admin->id, $this->roster($this->member));
    }

    // Your example: an admin who manages a fellow admin puts themselves on that admin's black list.
    public function test_an_admin_hides_themself_from_a_fellow_admin_s_reports(): void
    {
        $this->change($this->developer, $this->admin, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();

        $this->list($this->admin, $this->fellow, 'block', [$this->admin])->assertOk();

        $this->assertNotContains((string) $this->admin->id, $this->roster($this->fellow));
        $this->assertContains((string) $this->member->id, $this->roster($this->fellow));
    }

    // Like a limit, a list counts only while its maker still holds the power.
    public function test_a_list_stops_counting_once_its_maker_loses_the_power(): void
    {
        $this->list($this->admin, $this->member, 'block', [$this->fellow])->assertStatus(422);
        $this->change($this->developer, $this->member, ['key' => 'manage.reports', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->assertNull($this->power($this->member, 'manage.reports.unlisted'));
        $this->list($this->admin, $this->member, 'block', [$this->fellow])->assertOk();
        $this->assertNotContains((string) $this->fellow->id, $this->roster($this->member));

        $this->change($this->developer, $this->admin, ['key' => 'manage.reports', 'action' => 'off'])->assertOk();

        $this->assertContains((string) $this->fellow->id, $this->roster($this->member));
    }

    // Saving replaces the editor's list, a new kind starts over, and none clears it.
    public function test_saving_replaces_switches_kind_and_clears_the_list(): void
    {
        $rows = fn () => DB::connection('portal')->table('access_lists')->where('employee_id', $this->admin->id)->orderBy('subject_id')->get(['mode', 'subject_id'])
            ->map(static fn (object $row): string => $row->mode.':'.$row->subject_id)->all();

        $this->list($this->developer, $this->admin, 'allow', [$this->fellow, $this->member])->assertOk();
        $this->list($this->developer, $this->admin, 'allow', [$this->member])->assertOk();
        $this->assertSame(['allow:'.$this->member->id], $rows());

        $this->list($this->developer, $this->admin, 'block', [$this->fellow])->assertOk();
        $this->assertSame(['block:'.$this->fellow->id], $rows());

        $this->list($this->developer, $this->admin, 'block', [])->assertStatus(422)->assertJsonPath('message', 'Choose at least one person for the list.');
        $this->list($this->developer, $this->admin, 'none', [])->assertOk();
        $this->assertSame([], $rows());
        $this->change($this->developer, $this->admin, ['key' => 'home', 'action' => 'list', 'mode' => 'block', 'people' => [$this->member->id]])
            ->assertStatus(422)->assertJsonPath('message', 'That power has no white or black list.');
    }

    // Manage other admins from a fellow admin manages every other admin, never the giver, and cannot be passed on.
    public function test_manage_other_admins_from_a_fellow_admin_skips_the_giver_and_never_passes_on(): void
    {
        $third = $this->active("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 4)[3];
        $third->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->change($this->developer, $this->admin, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();
        $this->change($this->admin, $this->fellow, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();

        $editable = $this->editable($this->fellow);
        $this->assertTrue($editable[$third->id]);
        $this->assertFalse($editable[$this->admin->id]);
        $this->as($this->fellow)->getJson("/api/development/portal/manage/access/{$this->admin->id}")->assertNotFound();

        $keys = array_column($this->as($this->fellow)->getJson("/api/development/portal/manage/access/{$third->id}")->assertOk()->json('data'), 'key');
        $this->assertContains('manage.requests', $keys);
        $this->assertNotContains('manage.users.access.peers', $keys);
        $this->change($this->fellow, $third, ['key' => 'manage.users.access.peers', 'action' => 'on'])
            ->assertNotFound()->assertJsonPath('message', 'You do not hold that power.');

        // Taken back by the giver, the borrowed power ends and so does the hold over everyone else.
        $this->change($this->admin, $this->fellow, ['key' => 'manage.users.access.peers', 'action' => 'off'])->assertOk();
        $this->assertFalse($this->editable($this->fellow)[$third->id]);
    }

    // Your scenario: John (developer) and Nathaniel (developer) are both admins.
    public function test_default_mode_never_undoes_developer_mode_and_a_default_gift_is_an_equal_s(): void
    {
        [$john, $nathaniel] = [$this->developer, $this->fellow];
        $nathaniel->forceFill(['is_developer' => true])->save();
        $third = $this->active("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 4)[3];
        $third->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        // John in Developer mode gives Nathaniel Manage other admins; Nathaniel in Default gives it back.
        $this->change($john, $nathaniel, ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();
        $this->asDefault($nathaniel)->patchJson("/api/development/portal/manage/access/{$john->id}", ['key' => 'manage.users.access.peers', 'action' => 'on'])->assertOk();

        // John in Default borrowed it from Nathaniel: every other admin, never Nathaniel, never passed on.
        $editable = $this->editableBy($this->asDefault($john));
        $this->assertTrue($editable[$third->id]);
        $this->assertFalse($editable[$nathaniel->id]);
        $this->asDefault($john)->getJson("/api/development/portal/manage/access/{$nathaniel->id}")->assertNotFound();
        $keys = array_column($this->asDefault($john)->getJson("/api/development/portal/manage/access/{$third->id}")->assertOk()->json('data'), 'key');
        $this->assertNotContains('manage.users.access.peers', $keys);

        // In Developer mode John still manages Nathaniel and can take the power back.
        $this->change($john, $nathaniel, ['key' => 'manage.users.access.peers', 'action' => 'off'])->assertOk();
        $this->assertNull(collect($this->asDefault($nathaniel)->getJson('/api/development/portal/access')->assertOk()->json('data'))->firstWhere('key', 'manage.users.access.peers'));
    }

    // What you gave in Developer mode is not "given by you" in Default, and Default cannot change it.
    public function test_a_developer_mode_gift_is_locked_in_default(): void
    {
        $this->change($this->developer, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();

        $rule = $this->rule($this->asDefault($this->developer)->getJson("/api/development/portal/manage/access/{$this->member->id}"), 'calendar.create');
        $this->assertTrue($rule['holds']);
        $this->assertNull($rule['givenByYou']);
        $this->assertTrue($rule['lockedToDeveloperMode']);
        foreach (['off', 'on'] as $action) {
            $this->asDefault($this->developer)->patchJson("/api/development/portal/manage/access/{$this->member->id}", ['key' => 'calendar.create', 'action' => $action])
                ->assertStatus(422)->assertJsonPath('message', 'You made this in Developer mode. Switch to Developer mode to change it.');
        }
        $this->assertNotNull($this->power($this->member, 'calendar.create'));

        $mine = $this->rule($this->as($this->developer)->getJson("/api/development/portal/manage/access/{$this->member->id}"), 'calendar.create');
        $this->assertNotNull($mine['givenByYou']);
        $this->assertFalse($mine['lockedToDeveloperMode']);
    }

    // A developer's Default gift is judged as their role: it ends once they no longer manage the person.
    public function test_a_developer_s_default_gift_counts_only_as_their_role(): void
    {
        $this->asDefault($this->developer)->patchJson("/api/development/portal/manage/access/{$this->member->id}", ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->change($this->developer, $this->outsider, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->power($this->member, 'calendar.create'));

        $this->member->forceFill(['department' => 'Engineering'])->save();

        $this->assertNull($this->power($this->member, 'calendar.create'));
        $this->assertNotNull($this->power($this->outsider, 'calendar.create'));
    }

    // A fresh Default sign-in, even for a developer.
    private function asDefault(Employee $employee): self
    {
        $token = (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');

        return $this->withToken($token);
    }

    /**
     * @return array<int, bool>
     */
    private function editableBy(self $viewer): array
    {
        $rows = $viewer->getJson('/api/development/portal/manage/users?per_page=100')->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $row): array => [(int) $row['id'] => $row['canEditAccess']])->all();
    }

    /**
     * @param  list<Employee>  $people
     */
    private function list(Employee $editor, Employee $target, string $mode, array $people): TestResponse
    {
        return $this->change($editor, $target, [
            'key' => 'manage.reports',
            'action' => 'list',
            'mode' => $mode,
            'people' => array_map(static fn (Employee $person): int => (int) $person->id, $people),
        ]);
    }

    /**
     * @return list<string>
     */
    private function roster(Employee $viewer): array
    {
        return $this->as($viewer)->getJson('/api/development/portal/manage/reports')->assertOk()->json('employees.*.value');
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
