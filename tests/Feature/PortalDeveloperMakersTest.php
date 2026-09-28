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
 * Developer mode and Default are two makers. What a developer does in Developer mode is a
 * developer's act; what they do in Default is judged on their role like anyone's. Developer mode
 * sees and changes both; Default sees what Developer mode did but never changes it.
 *
 * John and Nathaniel are both developer admins, as on the real roster; Third is a plain admin.
 */
class PortalDeveloperMakersTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    private const PEERS = 'manage.users.access.peers';

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private Employee $john;

    private Employee $nathaniel;

    private Employee $third;

    private Employee $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();

        [$this->john, $this->nathaniel, $this->third] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 3);
        $this->member = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 1)[0];
        $this->john->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->nathaniel->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->third->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->member->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
    }

    // Your report, step by step.
    public function test_the_reported_scenario_end_to_end(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->nathaniel, self::PEERS));

        $this->change('default', $this->nathaniel, $this->john, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->assertTrue($this->editable('default', $this->john)[$this->third->id]);
        $this->assertFalse($this->editable('default', $this->john)[$this->nathaniel->id]);

        // Nathaniel in Developer mode owns his Default gift too.
        $rule = $this->sheetRule('dev', $this->nathaniel, $this->john, self::PEERS);
        $this->assertNotNull($rule['givenByYou']);
        $this->assertFalse($rule['lockedToDeveloperMode']);

        // Switching it off in Developer mode takes it away, and leaves no stray gift or limit behind.
        $this->change('dev', $this->nathaniel, $this->john, ['key' => self::PEERS, 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->john, self::PEERS));
        $this->assertFalse($this->editable('default', $this->john)[$this->third->id]);
        $this->assertSame(0, DB::connection('portal')->table('access_grants')->where('employee_id', $this->john->id)->count());
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // Your scenario: a developer overrides their own Default gift, and it becomes a Developer mode gift.
    public function test_a_developer_overrides_their_own_default_gift(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->john, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->assertFalse($this->editable('default', $this->john)[$this->nathaniel->id]);

        $rule = $this->sheetRule('dev', $this->nathaniel, $this->john, self::PEERS);
        $this->assertTrue($rule['yoursFromDefault']);
        $this->assertTrue($rule['canOverride']);
        $this->assertFalse($this->sheetRule('default', $this->nathaniel, $this->john, self::PEERS)['canOverride']);

        $this->change('dev', $this->nathaniel, $this->john, ['key' => self::PEERS, 'action' => 'override', 'on' => true])->assertOk();

        $grant = DB::connection('portal')->table('access_grants')->where('employee_id', $this->john->id)->where('permission_key', self::PEERS)->sole();
        $this->assertSame($this->nathaniel->id, (int) $grant->granted_by);
        $this->assertTrue((bool) $grant->as_developer);
        $this->assertTrue($this->editable('default', $this->john)[$this->nathaniel->id]);
        $this->assertFalse($this->sheetRule('dev', $this->nathaniel, $this->john, self::PEERS)['yoursFromDefault']);
    }

    // Your own Default limit becomes a Developer mode limit the same way.
    public function test_a_developer_overrides_their_own_default_limit(): void
    {
        $this->change('default', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'off'])->assertOk();
        $this->assertTrue($this->sheetRule('dev', $this->john, $this->member, 'reports.daily')['canOverride']);

        $this->change('dev', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'override', 'on' => false])->assertOk();

        $this->assertTrue((bool) DB::connection('portal')->table('access_limits')->where('employee_id', $this->member->id)->sole()->as_developer);
        $this->assertTrue($this->sheetRule('default', $this->john, $this->member, 'reports.daily')['lockedToDeveloperMode']);
    }

    // A borrowed power is locked to everyone but a developer, whose Override ends it; a limit on it ends it too.
    public function test_a_borrowed_power_ends_by_override_or_by_a_limit(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->third, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->assertTrue($this->editable('default', $this->third)[$this->john->id]);
        $this->change('dev', $this->john, $this->third, ['key' => self::PEERS, 'action' => 'off'])->assertStatus(422);

        $this->change('dev', $this->john, $this->third, ['key' => self::PEERS, 'action' => 'override', 'on' => false])->assertOk();

        $this->assertNull($this->ownPower('default', $this->third, self::PEERS));
        $this->assertFalse($this->editable('default', $this->third)[$this->john->id]);

        // Lent again, a stored limit from a developer ends it just as surely.
        $this->change('default', $this->nathaniel, $this->third, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        DB::connection('portal')->table('access_limits')->insert(['employee_id' => $this->third->id, 'permission_key' => self::PEERS, 'limited_by' => $this->john->id, 'as_developer' => true]);
        $this->assertNull($this->ownPower('default', $this->third, self::PEERS));
    }

    // A loan lasts only while the lender still holds the power from their role or from above.
    public function test_a_loan_ends_when_the_lender_loses_their_own_power(): void
    {
        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'on'])->assertOk();
        $this->change('default', $this->nathaniel, $this->third, ['key' => self::PEERS, 'action' => 'on'])->assertOk();

        $this->change('dev', $this->john, $this->nathaniel, ['key' => self::PEERS, 'action' => 'off'])->assertOk();

        $this->assertNull($this->ownPower('default', $this->nathaniel, self::PEERS));
        $this->assertNull($this->ownPower('default', $this->third, self::PEERS));
        $this->assertFalse($this->editable('default', $this->third)[$this->john->id]);
    }

    // Two developer admins in Default are equals: without Manage other admins neither opens the other.
    public function test_two_developer_admins_in_default_are_equals(): void
    {
        $this->assertFalse($this->editable('default', $this->nathaniel)[$this->john->id]);
        $this->assertTrue($this->editable('dev', $this->nathaniel)[$this->john->id]);
        $this->asMode('default', $this->nathaniel)->getJson("/api/development/portal/manage/access/{$this->john->id}")->assertNotFound();
    }

    // Default shows a list made in Developer mode read only; Developer mode replaces the Default list.
    public function test_lists_follow_the_mode_they_were_made_in(): void
    {
        $this->change('default', $this->john, $this->member, ['key' => 'manage.reports', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->setList('default', $this->john, $this->member, 'block', [$this->john])->assertOk();
        $this->assertSame('block', $this->sheetRule('default', $this->john, $this->member, 'manage.reports')['list']['mode']);
        $this->assertSame('block', $this->sheetRule('dev', $this->john, $this->member, 'manage.reports')['list']['mode']);

        $this->setList('dev', $this->john, $this->member, 'allow', [$this->third])->assertOk();
        $this->assertSame(
            ['allow:1:'.$this->third->id],
            DB::connection('portal')->table('access_lists')->where('employee_id', $this->member->id)->get()
                ->map(static fn (object $row): string => $row->mode.':'.(int) $row->as_developer.':'.$row->subject_id)->all(),
        );

        $inDefault = $this->sheetRule('default', $this->john, $this->member, 'manage.reports');
        $this->assertSame('allow', $inDefault['list']['mode']);
        $this->assertTrue($inDefault['lockedToDeveloperMode']);
        $this->setList('default', $this->john, $this->member, 'none', [])
            ->assertStatus(422)->assertJsonPath('message', 'You made this in Developer mode. Switch to Developer mode to change it.');
        $this->assertEqualsCanonicalizing(
            [(string) $this->member->id, (string) $this->third->id],
            $this->asMode('default', $this->member)->getJson('/api/development/portal/manage/reports')->assertOk()->json('employees.*.value'),
        );
    }

    // A reach set in Developer mode replaces the Default gift rather than sitting beside it.
    public function test_a_developer_mode_gift_replaces_the_default_gift(): void
    {
        $this->change('default', $this->john, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])->assertOk();

        $this->change('dev', $this->john, $this->member, ['key' => 'manage.requests.leave', 'action' => 'reach', 'reach' => 'own'])->assertOk();

        $rows = DB::connection('portal')->table('access_grants')->where('employee_id', $this->member->id)->where('permission_key', 'manage.requests.leave')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('own', $rows[0]->reach);
        $this->assertTrue((bool) $rows[0]->as_developer);
    }

    // Developer mode lifts a limit you made in Default.
    public function test_developer_mode_lifts_your_default_limit(): void
    {
        $this->change('default', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'off'])->assertOk();
        $this->assertNull($this->ownPower('default', $this->member, 'reports.daily'));
        $this->assertTrue($this->sheetRule('dev', $this->john, $this->member, 'reports.daily')['limitedByYou']);

        $this->change('dev', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'on'])->assertOk();

        $this->assertNotNull($this->ownPower('default', $this->member, 'reports.daily'));
        $this->assertSame(0, DB::connection('portal')->table('access_limits')->count());
    }

    // Default cannot reach anything Developer mode set, above it or under it, and every other power still works.
    public function test_default_is_locked_out_of_whatever_developer_mode_set_on_a_branch(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $locked = 'You made this in Developer mode. Switch to Developer mode to change it.';

        foreach (['manage.requests', 'manage.requests.leave', 'manage.requests.leave.decide'] as $key) {
            $this->change('default', $this->john, $this->member, ['key' => $key, 'action' => 'off'])->assertStatus(422)->assertJsonPath('message', $locked);
        }
        $this->change('default', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'manage.requests.leave.decide'));

        // A limit set in Developer mode on a page holds its tabs off too, so they are locked as well.
        $this->change('dev', $this->john, $this->member, ['key' => 'reports', 'action' => 'off'])->assertOk();
        $this->change('default', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'on'])->assertStatus(422)->assertJsonPath('message', $locked);
        $this->assertTrue($this->sheetRule('default', $this->john, $this->member, 'reports.daily')['lockedToDeveloperMode']);
    }

    // Once no longer a developer, what they did in Developer mode stops counting; what they did in Default stays.
    public function test_losing_the_developer_flag_ends_only_developer_mode_acts(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->change('default', $this->john, $this->member, ['key' => 'projects.create', 'action' => 'on'])->assertOk();

        $this->john->forceFill(['is_developer' => false])->save();

        $this->assertNull($this->ownPower('default', $this->member, 'calendar.create'));
        $this->assertNotNull($this->ownPower('default', $this->member, 'projects.create'));
    }
}
