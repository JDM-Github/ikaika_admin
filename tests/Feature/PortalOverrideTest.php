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
 * Someone else's gift or limit is locked to every editor; only a developer in Developer mode takes
 * it over, with Override, for the power and every tab under it. What anyone did in Developer mode
 * reads as "a developer" to everyone not in Developer mode, and by name with the mode to those in it,
 * All Logs included.
 */
class PortalOverrideTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    private const SOMEONE_ELSE = 'Someone else gave this power, so only a developer can change it, with Override.';

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private Employee $john;

    private Employee $nathaniel;

    private Employee $third;

    private Employee $member;

    private Employee $executive;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();

        [$this->john, $this->nathaniel, $this->third] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 3);
        $this->member = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 1)[0];
        $this->executive = $this->activeWhere("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];
        $this->john->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->nathaniel->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        foreach ([$this->third, $this->member, $this->executive] as $person) {
            $person->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        }
    }

    // An admin's gift is locked to everyone else; a developer's Override takes it and every tab under it over, reach kept.
    public function test_override_takes_a_gift_and_its_tabs_over(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->change('default', $this->nathaniel, $this->member, ['key' => 'manage.requests.leave', 'action' => 'off'])
            ->assertStatus(422)->assertJsonPath('message', self::SOMEONE_ELSE);
        $this->change('default', $this->nathaniel, $this->member, ['key' => 'manage.requests.leave', 'action' => 'reach', 'reach' => 'own'])
            ->assertStatus(422)->assertJsonPath('message', self::SOMEONE_ELSE);
        $this->assertTrue($this->sheetRule('dev', $this->john, $this->member, 'manage.requests.leave')['canOverride']);

        $this->change('dev', $this->john, $this->member, ['key' => 'manage.requests.leave', 'action' => 'override', 'on' => true])->assertOk();

        $rows = DB::connection('portal')->table('access_grants')->where('employee_id', $this->member->id)
            ->whereIn('permission_key', ['manage.requests.leave', 'manage.requests.leave.decide'])->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($this->john->id, (int) $row->granted_by);
            $this->assertTrue((bool) $row->as_developer);
        }
        $this->assertSame('department', $this->ownPower('default', $this->member, 'manage.requests.leave')['reach'] ?? null);
        foreach (['manage.requests.leave', 'manage.requests.leave.decide'] as $key) {
            $rule = $this->sheetRule('default', $this->third, $this->member, $key);
            $this->assertSame(['a developer'], $rule['givenBy']);
            $this->assertNull($rule['givenByYou']);
            $this->assertTrue($rule['lockedByOthers']);
            $this->assertFalse($rule['canOverride']);
            $this->change('default', $this->third, $this->member, ['key' => $key, 'action' => 'off'])->assertStatus(422)->assertJsonPath('message', self::SOMEONE_ELSE);
        }
    }

    // Override on a limit: left off, the limit becomes the developer's; the earlier limiter can no longer lift it.
    public function test_override_takes_a_limit_over(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'reports.daily', 'action' => 'off'])->assertOk();
        $this->assertTrue($this->sheetRule('dev', $this->john, $this->member, 'reports.daily')['canOverride']);

        $this->change('dev', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'override', 'on' => false])->assertOk();

        $this->assertNull($this->ownPower('default', $this->member, 'reports.daily'));
        $limit = DB::connection('portal')->table('access_limits')->where('employee_id', $this->member->id)->sole();
        $this->assertSame($this->john->id, (int) $limit->limited_by);
        $this->assertSame(['a developer'], $this->sheetRule('default', $this->third, $this->member, 'reports.daily')['limitedBy']);
        $this->change('default', $this->third, $this->member, ['key' => 'reports.daily', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'A developer limited that power, so it cannot be switched on.');

        // Now the developer's own limit, so an ordinary switch on in Developer mode lifts it.
        $this->change('dev', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'reports.daily'));
    }

    // Override on a limited power gives it back at the reach chosen.
    public function test_override_on_lifts_a_limit_at_the_chosen_reach(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->change('dev', $this->nathaniel, $this->member, ['key' => 'manage.requests.leave', 'action' => 'override', 'on' => false])->assertOk();
        $this->assertNull($this->ownPower('default', $this->member, 'manage.requests.leave'));

        $this->change('dev', $this->john, $this->member, ['key' => 'manage.requests.leave', 'action' => 'on', 'reach' => 'own'])->assertOk();

        $this->assertSame('own', $this->ownPower('default', $this->member, 'manage.requests.leave')['reach'] ?? null);
    }

    // Only a developer in Developer mode overrides: not in Default, not an executive, not an admin.
    public function test_override_is_for_developer_mode_only(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();

        foreach ([$this->john, $this->nathaniel, $this->executive] as $editor) {
            $this->change('default', $editor, $this->member, ['key' => 'calendar.create', 'action' => 'override', 'on' => false])
                ->assertForbidden()->assertJsonPath('message', 'Only a developer in Developer mode can override.');
        }
        $this->assertNotNull($this->ownPower('default', $this->member, 'calendar.create'));
    }

    // A tab under a page someone limited is locked but not overridable on its own: override the page.
    public function test_a_tab_under_a_limited_page_is_overridden_through_its_page(): void
    {
        $this->change('default', $this->third, $this->member, ['key' => 'reports', 'action' => 'off'])->assertOk();

        $tab = $this->sheetRule('dev', $this->john, $this->member, 'reports.daily');
        $this->assertTrue($tab['lockedByOthers']);
        $this->assertFalse($tab['canOverride']);
        $this->assertTrue($this->sheetRule('dev', $this->john, $this->member, 'reports')['canOverride']);
        $this->change('dev', $this->john, $this->member, ['key' => 'reports.daily', 'action' => 'override', 'on' => true])
            ->assertStatus(422)->assertJsonPath('message', 'A page above this power is limited. Override that page first.');

        $this->change('dev', $this->john, $this->member, ['key' => 'reports', 'action' => 'override', 'on' => true])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->member, 'reports.daily'));
    }

    // Names follow the viewer's mode: "a developer" outside Developer mode, the name and mode inside it.
    public function test_developer_mode_acts_are_named_by_the_viewer_s_mode(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $this->setList('dev', $this->john, $this->third, 'block', [$this->member])->assertOk();
        $named = $this->displayName($this->john).' (Developer mode)';

        $this->assertSame(['a developer'], $this->ownPower('default', $this->member, 'calendar.create')['givenBy'] ?? null);
        $this->assertSame(['a developer'], $this->sheetRule('default', $this->nathaniel, $this->member, 'calendar.create')['givenBy']);
        $this->assertSame([$named], $this->sheetRule('dev', $this->nathaniel, $this->member, 'calendar.create')['givenBy']);
        $this->assertSame([['name' => 'a developer', 'mode' => 'block']], $this->ownPower('default', $this->third, 'manage.reports')['lists'] ?? null);
        $this->assertSame([['name' => $named, 'mode' => 'block']], $this->sheetRule('dev', $this->nathaniel, $this->third, 'manage.reports')['list']['others']);

        // A developer's Default act is theirs by name, to everyone.
        $this->change('default', $this->nathaniel, $this->member, ['key' => 'projects.manager', 'action' => 'on'])->assertOk();
        $this->assertSame([$this->displayName($this->nathaniel)], $this->ownPower('default', $this->member, 'projects.manager')['givenBy'] ?? null);
    }

    // Without See developers' activity a developer's rows are not there at all; with it, a Developer mode
    // row still hides who acted from anyone not in Developer mode, even through filters and search.
    public function test_all_logs_hides_a_developer_mode_actor(): void
    {
        $this->change('dev', $this->john, $this->member, ['key' => 'calendar.create', 'action' => 'on'])->assertOk();
        $logId = (int) DB::connection('portal')->table('logs')->where('employee_id', $this->john->id)->where('resource', 'manage.access')->orderByDesc('id')->value('id');
        $this->assertTrue((bool) DB::connection('portal')->table('logs')->where('id', $logId)->value('as_developer'));
        $row = static fn (array $rows): ?array => collect($rows)->firstWhere('id', (string) $logId);

        $this->assertNull($row($this->asMode('default', $this->third)->getJson('/api/development/portal/administration/all-logs?per_page=100')->assertOk()->json('data')));
        $this->asMode('default', $this->third)->getJson("/api/development/portal/administration/all-logs/{$logId}")->assertNotFound();
        $this->givePower($this->third, 'administration.logs.developers');

        $hidden = $row($this->asMode('default', $this->third)->getJson('/api/development/portal/administration/all-logs?per_page=100')->assertOk()->json('data'));
        $this->assertSame('A developer', $hidden['employeeName'] ?? null);
        $this->assertNull($hidden['employeeId']);
        $this->assertNull($hidden['ipAddress']);
        $this->assertNull($row($this->asMode('default', $this->third)->getJson("/api/development/portal/administration/all-logs?per_page=100&employee_id={$this->john->id}")->assertOk()->json('data')));
        $this->assertNull($row($this->asMode('default', $this->third)->getJson('/api/development/portal/administration/all-logs?per_page=100&q='.urlencode((string) $this->john->last_name))->assertOk()->json('data')));
        $this->assertSame('A developer', $this->asMode('default', $this->third)->getJson("/api/development/portal/administration/all-logs/{$logId}")->assertOk()->json('data.employeeName'));

        $shown = $row($this->asMode('dev', $this->nathaniel)->getJson('/api/development/portal/administration/all-logs?per_page=100')->assertOk()->json('data'));
        $this->assertSame($this->displayName($this->john).' (Developer mode)', $shown['employeeName'] ?? null);
        $this->assertSame((string) $this->john->id, $shown['employeeId']);

        // The developer's own stream is theirs, never masked.
        $own = $row($this->asMode('default', $this->john)->getJson('/api/development/portal/user/logs?per_page=100')->assertOk()->json('data'));
        $this->assertSame((string) $this->john->id, $own['employeeId'] ?? null);
    }

    // Every sign-in starts in Default, so a developer's names them, but only to someone who may see developers.
    public function test_a_developers_sign_in_shows_by_name_only_with_the_power(): void
    {
        $this->asMode('default', $this->john)->getJson('/api/development/portal/user/logs')->assertOk();
        $signIn = (int) DB::connection('portal')->table('logs')
            ->where('employee_id', $this->john->id)->where('resource', 'auth.login')->orderByDesc('id')->value('id');
        $this->assertFalse((bool) DB::connection('portal')->table('logs')->where('id', $signIn)->value('as_developer'));
        $row = fn (): ?array => collect($this->asMode('default', $this->third)->getJson('/api/development/portal/administration/all-logs?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', (string) $signIn);

        $this->assertNull($row());
        $this->givePower($this->third, 'administration.logs.developers');
        $this->assertSame($this->displayName($this->john), $row()['employeeName'] ?? null);
    }

    // A personal default, the way Nathaniel holds it: nobody's role starts with it.
    private function givePower(Employee $employee, string $key): void
    {
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $employee->id, 'permission_key' => $key, 'reach' => null]);
    }

    private function displayName(Employee $employee): string
    {
        return trim(trim((string) $employee->first_name).' '.trim((string) $employee->last_name));
    }
}
