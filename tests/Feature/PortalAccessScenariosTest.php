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
 * Longer Access Rule stories across gifts, loans, limits, lists, moves and modes. John and Nathaniel
 * are developer admins, Third and Fourth plain admins, all in MIS with two MIS members and one
 * Engineering member; the executive is the roster's only one.
 */
class PortalAccessScenariosTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    private const PEERS = 'manage.users.access.peers';

    private const LOCKED = 'You made this in Developer mode. Switch to Developer mode to change it.';

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private Employee $john;

    private Employee $nathaniel;

    private Employee $third;

    private Employee $fourth;

    private Employee $member;

    private Employee $member2;

    private Employee $outsider;

    private Employee $executive;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();

        [$this->john, $this->nathaniel, $this->third, $this->fourth] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 4);
        [$this->member, $this->member2, $this->outsider] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 3);
        $this->executive = $this->activeWhere("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];
        foreach ([$this->john, $this->nathaniel] as $developer) {
            $developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        }
        foreach ([$this->third, $this->fourth, $this->member, $this->member2, $this->executive] as $person) {
            $person->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        }
        $this->outsider->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
    }

    // A chain falls with its top and comes back with it: nobody below redoes anything.
    public function test_a_chain_falls_and_returns_with_its_top(): void
    {
        $this->useBackupAsAPlainPower();
        $this->change('dev', $this->john, $this->third, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->change('default', $this->third, $this->member, ['key' => 'administration.backup.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'administration.backup.create'));

        $this->change('dev', $this->john, $this->third, ['key' => 'administration.backup', 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->member, 'administration.backup.create'));
        $this->assertNotNull($this->ownPower('default', $this->member, 'administration'));

        $this->change('dev', $this->john, $this->third, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'administration.backup.create'));
    }

    // Stale rows where two admins lent each other Manage other admins, with no base between them, grant nothing.
    public function test_mutual_loans_cannot_keep_each_other_alive(): void
    {
        $this->grantRow($this->nathaniel, $this->third, self::PEERS);
        $this->grantRow($this->third, $this->nathaniel, self::PEERS);

        $this->assertNull($this->ownPower('default', $this->nathaniel, self::PEERS));
        $this->assertNull($this->ownPower('default', $this->third, self::PEERS));
        $this->assertFalse($this->editable('default', $this->nathaniel)[$this->fourth->id]);

        // With a base under one of them, only the loan from the base holder counts.
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->assertTrue($this->editable('default', $this->third)[$this->fourth->id]);
        $this->assertFalse($this->editable('default', $this->third)[$this->nathaniel->id]);

        // Take the base away and both fall, rather than holding each other up.
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->nathaniel, self::PEERS));
        $this->assertNull($this->ownPower('default', $this->third, self::PEERS));
    }

    // Switching your loan off takes back yours alone: another lender's stays, and you are no longer off limits.
    public function test_switching_your_loan_off_leaves_another_lender_s(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('dev', $this->john, $this->third, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->grantRow($this->fourth, $this->third, self::PEERS);
        $this->assertFalse($this->editable('default', $this->fourth)[$this->third->id]);
        $this->assertFalse($this->editable('default', $this->fourth)[$this->nathaniel->id]);
        $this->assertTrue($this->editable('default', $this->fourth)[$this->john->id]);

        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'off'])->assertOk();

        $this->assertNotNull($this->ownPower('default', $this->fourth, self::PEERS));
        $this->assertTrue($this->editable('default', $this->fourth)[$this->nathaniel->id]);
        $this->assertFalse($this->editable('default', $this->fourth)[$this->third->id]);
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // A borrower passes on only what came from their role or from above, and those gifts end with the loan.
    public function test_a_borrower_passes_on_only_their_own_powers(): void
    {
        $this->useBackupAsAPlainPower();
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('dev', $this->john, $this->nathaniel, ['key' => 'manage.reports.unlisted', 'action' => 'on'])->assertOk();
        $this->change('dev', $this->john, $this->fourth, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => 'manage.reports.unlisted', 'action' => 'on'])->assertOk();

        $this->change('default', $this->fourth, $this->third, ['key' => 'administration.backup', 'action' => 'on'])->assertOk();
        $this->change('default', $this->fourth, $this->third, ['key' => 'manage.reports.unlisted', 'action' => 'on'])
            ->assertNotFound()->assertJsonPath('message', 'You do not hold that power.');
        $this->assertNotNull($this->ownPower('default', $this->third, 'administration.backup'));

        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'off'])->assertOk();

        $this->assertNull($this->ownPower('default', $this->third, 'administration.backup'));
        $this->assertNotNull($this->ownPower('default', $this->fourth, 'administration.backup'));
    }

    // Moving someone out of the lender's department pauses the loan; moving them back resumes it.
    public function test_a_department_move_pauses_and_resumes_a_loan(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();

        $this->fourth->forceFill(['department' => 'Engineering'])->save();
        $this->assertNull($this->ownPower('default', $this->fourth, self::PEERS));

        $this->fourth->forceFill(['department' => 'MIS'])->save();
        $this->assertNotNull($this->ownPower('default', $this->fourth, self::PEERS));
    }

    // A demoted admin cannot hold Manage other admins, so the loan and everything they gave with it pause.
    public function test_a_demotion_pauses_a_loan_and_the_gifts_made_through_it(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->fourth, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->change('default', $this->fourth, $this->third, ['key' => 'calendar.download', 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->third, 'calendar.download'));

        $this->fourth->forceFill(['role' => 'User'])->save();

        $this->assertNull($this->ownPower('default', $this->fourth, self::PEERS));
        $this->assertNull($this->ownPower('default', $this->member, 'calendar.create'));
        $this->assertNotNull($this->ownPower('default', $this->third, 'calendar.download'));

        $this->fourth->forceFill(['role' => 'Admin'])->save();
        $this->assertNotNull($this->ownPower('default', $this->member, 'calendar.create'));
        $this->assertNull($this->ownPower('default', $this->third, 'calendar.download'));
    }

    // An inactive giver's gifts pause and resume with them.
    public function test_an_inactive_giver_s_gifts_pause(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();

        $this->third->forceFill(['status' => 'Inactive'])->save();
        $this->assertNull($this->ownPower('default', $this->member, 'calendar.create'));

        $this->third->forceFill(['status' => 'Active'])->save();
        $this->assertNotNull($this->ownPower('default', $this->member, 'calendar.create'));
    }

    // One executive cannot lift what a developer limited on another, even while managing them.
    public function test_an_executive_cannot_lift_a_developer_s_limit_on_a_fellow_executive(): void
    {
        $this->fourth->forceFill(['role_level' => 'Executive'])->save();
        $this->change('dev', $this->john, $this->executive, ['key' => 'email', 'action' => 'off'])->assertOk();
        $this->assertTrue($this->editable('default', $this->fourth)[$this->executive->id]);

        $this->change('default', $this->fourth, $this->executive, ['key' => 'email', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'A developer limited that power, so it cannot be switched on.');

        $this->assertNull($this->ownPower('default', $this->executive, 'email'));
    }

    // The president as a developer: an executive in Default, everything in Developer mode, and each mode's acts its own.
    public function test_an_executive_developer_is_an_executive_in_default(): void
    {
        $this->executive->forceFill(['is_developer' => true])->save();

        $this->assertNull($this->ownPower('default', $this->executive, 'home'));
        $this->assertNull($this->ownPower('default', $this->executive, 'developer.rules'));
        $this->assertNotNull($this->ownPower('dev', $this->executive, 'home'));
        $this->assertNotNull($this->ownPower('dev', $this->executive, 'developer.rules'));

        $this->change('default', $this->executive, $this->third, ['key' => 'calendar.create', 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->third, 'calendar.create'));
        foreach (['default', 'dev'] as $mode) {
            $rule = $this->sheetRule($mode, $this->executive, $this->third, 'calendar.create');
            $this->assertTrue($rule['limitedByYou']);
            $this->assertFalse($rule['lockedToDeveloperMode']);
        }
    }

    // Switching your own power off or narrowing it is your convenience only: what you gave keeps working.
    public function test_self_toggles_and_self_reach_never_touch_what_you_gave(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->asMode('default', $this->third)->patchJson('/api/development/portal/access/reach', ['key' => 'manage.requests.leave', 'reach' => 'own'])->assertOk();
        $this->asMode('default', $this->third)->patchJson('/api/development/portal/access/switches', ['key' => 'manage.requests.leave', 'on' => false])->assertOk();

        $this->assertSame('department', $this->ownPower('default', $this->member, 'manage.requests.leave')['reach'] ?? null);
    }

    // Developer mode gives others nothing, nobody opens their own sheet, and developer tools never show on one.
    public function test_developer_mode_is_the_developer_s_alone(): void
    {
        $this->asMode('dev', $this->john);

        $this->assertNull($this->ownPower('default', $this->nathaniel, 'developer.rules'));
        foreach (['default', 'dev'] as $mode) {
            $this->asMode($mode, $this->john)->getJson("/api/development/portal/manage/access/{$this->john->id}")->assertNotFound();
        }
        $keys = array_column($this->asMode('dev', $this->john)->getJson("/api/development/portal/manage/access/{$this->member->id}")->assertOk()->json('data'), 'key');
        $this->assertEmpty(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'developer')));
    }

    // A borrower lists only people they can see, can hide themselves, and their lists end with the loan.
    public function test_lists_made_through_a_loan(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->setList('dev', $this->john, $this->fourth, 'block', [$this->member])->assertOk();

        $picker = collect($this->asMode('default', $this->fourth)->getJson("/api/development/portal/manage/access/{$this->third->id}")->assertOk()->json('listPeople')['manage.reports'] ?? [])->pluck('id')->all();
        $this->assertNotContains((string) $this->member->id, $picker);
        $this->setList('default', $this->fourth, $this->third, 'block', [$this->member])
            ->assertStatus(422)->assertJsonPath('message', 'You can only list people you can see yourself.');
        $this->setList('default', $this->fourth, $this->third, 'block', [$this->fourth])->assertOk();
        $this->assertNotContains((string) $this->fourth->id, $this->reportsRoster('default', $this->third));

        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'off'])->assertOk();

        $this->assertContains((string) $this->fourth->id, $this->reportsRoster('default', $this->third));
    }

    // Ignore white and black lists frees someone from every list, until they switch it off themselves.
    public function test_ignoring_lists_and_switching_that_off_yourself(): void
    {
        $this->setList('dev', $this->john, $this->nathaniel, 'block', [$this->third])->assertOk();
        $this->assertNotContains((string) $this->third->id, $this->reportsRoster('default', $this->nathaniel));

        $this->change('dev', $this->john, $this->nathaniel, ['key' => 'manage.reports.unlisted', 'action' => 'on'])->assertOk();
        $this->assertContains((string) $this->third->id, $this->reportsRoster('default', $this->nathaniel));

        $this->asMode('default', $this->nathaniel)->patchJson('/api/development/portal/access/switches', ['key' => 'manage.reports.unlisted', 'on' => false])->assertOk();
        $this->assertNotContains((string) $this->third->id, $this->reportsRoster('default', $this->nathaniel));
    }

    // A white list narrows; it never adds someone the reach does not cover.
    public function test_a_white_list_never_reaches_past_the_reach(): void
    {
        $this->setList('dev', $this->john, $this->third, 'allow', [$this->outsider, $this->member])->assertOk();

        $this->assertEqualsCanonicalizing([(string) $this->third->id, (string) $this->member->id], $this->reportsRoster('default', $this->third));
    }

    // A limit made in Developer mode outlives it: another developer's Default cannot lift it, only Developer mode can.
    public function test_a_developer_mode_limit_holds_until_developer_mode_lifts_it(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'off'])->assertOk();

        $this->change('default', $this->nathaniel, $this->member, ['key' => 'reports.daily', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'A developer limited that power, so it cannot be switched on.');
        $this->change('default', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', self::LOCKED);

        // Another developer in Developer mode sees who, and lifts it only by overriding.
        $this->change('dev', $this->nathaniel, $this->member, ['key' => 'reports.daily', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', $this->displayName($this->john).' (Developer mode) limited that power, so it cannot be switched on.');
        $this->assertTrue($this->sheetRule('dev', $this->nathaniel, $this->member, 'reports.daily')['canOverride']);
        $this->change('dev', $this->nathaniel, $this->member, ['key' => 'reports.daily', 'action' => 'override', 'on' => true])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'reports.daily'));
    }

    // What a developer did in Default is judged on their role even while they are in Developer mode.
    public function test_a_default_gift_is_judged_on_the_role_even_while_its_maker_is_in_developer_mode(): void
    {
        $this->change('default', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->assertTrue($this->sheetRule('dev', $this->john, $this->member, 'calendar.create')['holds']);

        $this->john->forceFill(['department' => 'Engineering'])->save();

        $this->assertFalse($this->sheetRule('dev', $this->john, $this->member, 'calendar.create')['holds']);
        $this->assertNull($this->ownPower('default', $this->member, 'calendar.create'));
    }

    // A limit on a developer's Default, made through a power they gave: taking that power back ends the limit.
    public function test_a_developer_ends_a_limit_on_themselves_by_taking_back_the_power_behind_it(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->john, ['key' => 'calendar.create', 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->john, 'calendar.create'));
        $this->assertNotNull($this->ownPower('dev', $this->john, 'calendar.create'));

        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'off'])->assertOk();

        $this->assertNotNull($this->ownPower('default', $this->john, 'calendar.create'));
    }

    // Executives ignore lists by default; a developer limiting that power makes the lists bite.
    public function test_an_executive_s_lists_apply_once_their_ignore_power_is_limited(): void
    {
        $this->setList('dev', $this->john, $this->executive, 'block', [$this->third])->assertOk();
        $this->assertContains((string) $this->third->id, $this->reportsRoster('default', $this->executive));

        $this->change('dev', $this->john, $this->executive, ['key' => 'manage.reports.unlisted', 'action' => 'off'])->assertOk();

        $this->assertNotContains((string) $this->third->id, $this->reportsRoster('default', $this->executive));
    }

    // Someone's own switch-off outlives the gift: given again, it stays off until they switch it on.
    public function test_a_self_switch_off_outlives_the_gift(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->asMode('default', $this->member)->patchJson('/api/development/portal/access/switches', ['key' => 'calendar.create', 'on' => false])->assertOk();
        $this->change('default', $this->third, $this->member, ['key' => 'calendar.create', 'action' => 'off'])->assertOk();
        $this->change('default', $this->third, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();

        $power = $this->ownPower('default', $this->member, 'calendar.create');
        $this->assertIsArray($power);
        $this->assertFalse($power['isSwitchedOn']);
        $this->assertArrayNotHasKey('calendar.create', $this->asMode('default', $this->member)->getJson('/api/development/portal/auth/me')->assertOk()->json('employee.rules'));
    }

    // Two developers: one's gift is locked to the other until overridden, and overriding makes it the overrider's.
    public function test_two_developers_undo_each_other_only_by_overriding(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->change('dev', $this->nathaniel, $this->member, ['key' => 'calendar.create', 'action' => 'off'])
            ->assertStatus(422)->assertJsonPath('message', 'Someone else gave this power, so only a developer can change it, with Override.');

        $this->change('dev', $this->nathaniel, $this->member, ['key' => 'calendar.create', 'action' => 'override', 'on' => false])->assertOk();

        $this->assertNull($this->ownPower('default', $this->member, 'calendar.create'));
        $this->assertSame(0, DB::connection('portal')->table('access_grants')->where('employee_id', $this->member->id)->count());
        $this->change('dev', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'calendar.create'));
    }

    // A lender in Developer mode right now still lends as their role, and sees the loan as theirs.
    public function test_a_lender_in_developer_mode_still_lends_as_their_role(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();

        $rule = $this->sheetRule('dev', $this->nathaniel, $this->fourth, self::PEERS);
        $this->assertTrue($rule['holds']);
        $this->assertNotNull($rule['givenByYou']);
        $this->assertTrue($this->editable('default', $this->fourth)[$this->third->id]);
    }

    // The session names a borrowed power while it counts, and drops it when the loan ends.
    public function test_the_session_follows_a_loan(): void
    {
        $rules = fn (): array => $this->asMode('default', $this->fourth)->getJson('/api/development/portal/auth/me')->assertOk()->json('employee.rules');
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->assertArrayHasKey(self::PEERS, $rules());

        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'off'])->assertOk();

        $this->assertArrayNotHasKey(self::PEERS, $rules());
    }

    // A manager who hid themselves from someone's Reports shows up again once they no longer manage them.
    public function test_a_self_list_ends_when_its_maker_moves_away(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'manage.reports', 'action' => 'on', 'reach' => 'all'])->assertOk();
        $this->setList('default', $this->third, $this->member, 'block', [$this->third])->assertOk();
        $this->assertNotContains((string) $this->third->id, $this->reportsRoster('default', $this->member));

        $this->third->forceFill(['department' => 'Engineering'])->save();
        $this->assertContains((string) $this->third->id, $this->reportsRoster('default', $this->member));

        $this->third->forceFill(['department' => 'MIS'])->save();
        $this->assertNotContains((string) $this->third->id, $this->reportsRoster('default', $this->member));
    }

    // Today: switching on a power someone already holds through another person's gift records nothing,
    // so a developer cannot turn a borrowed Manage other admins into the admin's own.
    public function test_switching_on_a_power_already_held_through_someone_else_records_nothing(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();

        $this->change('dev', $this->john, $this->fourth, ['key' => self::PEERS, 'action' => 'on'])->assertOk();

        $this->assertSame(0, DB::connection('portal')->table('access_grants')->where('employee_id', $this->fourth->id)->where('granted_by', $this->john->id)->count());
        $this->assertFalse($this->editable('default', $this->fourth)[$this->nathaniel->id]);
    }

    // A stored grant row, as an older or hand-edited database could hold it.
    private function grantRow(Employee $target, Employee $giver, string $key, bool $asDeveloper = false): void
    {
        DB::connection('portal')->table('access_grants')->insert([
            'employee_id' => $target->id,
            'permission_key' => $key,
            'granted_by' => $giver->id,
            'as_developer' => $asDeveloper,
        ]);
    }

    private function displayName(Employee $employee): string
    {
        return trim(trim((string) $employee->first_name).' '.trim((string) $employee->last_name));
    }
}
