<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesPortalAccess;
use Tests\TestCase;

/**
 * Administration / All Logs follows administration.logs: its reach, its executives flag, and See
 * developers' activity, without which nothing a developer did shows. That power stays with MIS
 * administrators and executives, and nobody gets it by being given All Logs.
 */
class PortalAllLogsRulesTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private Employee $admin;

    private Employee $engineeringAdmin;

    private Employee $developer;

    private Employee $otherDeveloper;

    private Employee $member;

    private Employee $outsider;

    private Employee $executive;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();

        $admins = "LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'";
        [$this->admin, $this->engineeringAdmin, $this->developer, $this->otherDeveloper] = $this->activeWhere($admins, 4);
        [$this->member, $this->outsider] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 2);
        $this->executive = $this->activeWhere("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];
        foreach ([$this->admin, $this->member] as $person) {
            $person->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        }
        foreach ([$this->engineeringAdmin, $this->outsider] as $person) {
            $person->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        }
        foreach ([$this->developer, $this->otherDeveloper] as $person) {
            $person->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        }
        $this->executive->forceFill(['is_developer' => false])->save();
        // Each sign-in writes one log row, the fixture every test reads.
        foreach ([$this->member, $this->outsider, $this->developer, $this->executive] as $person) {
            $this->asMode('default', $person)->getJson('/api/development/portal/access')->assertOk();
        }
    }

    public function test_all_logs_follows_its_rule_reach_and_executives_flag(): void
    {
        $this->asMode('default', $this->member)->getJson('/api/development/portal/administration/all-logs')
            ->assertForbidden()->assertJsonPath('message', PortalRules::REFUSAL);

        $seen = $this->people('default', $this->admin);
        $this->assertContains($this->member->id, $seen);
        $this->assertNotContains($this->outsider->id, $seen);
        $this->assertNotContains($this->executive->id, $seen);

        // Executives start at every department, executives included.
        $asExecutive = $this->people('default', $this->executive);
        $this->assertContains($this->outsider->id, $asExecutive);
        $this->assertContains($this->executive->id, $asExecutive);

        $this->asMode('default', $this->admin)->patchJson('/api/development/portal/access/reach', ['key' => 'administration.logs', 'reach' => 'own'])->assertOk();
        $this->assertNotContains($this->member->id, $this->people('default', $this->admin));
    }

    // Nothing a developer did shows without the power: not their rows, their name in the filter, or a search.
    public function test_a_developers_activity_needs_the_power_even_for_a_developer_in_default(): void
    {
        $signIn = $this->latestSignIn($this->developer);
        foreach ([$this->admin, $this->otherDeveloper] as $viewer) {
            $this->assertNotContains($this->developer->id, $this->people('default', $viewer));
            $this->asMode('default', $viewer)->getJson("/api/development/portal/administration/all-logs/{$signIn}")->assertNotFound();
            $users = $this->asMode('default', $viewer)->getJson('/api/development/portal/administration/all-logs?per_page=100')->json('filters.users.*.value');
            $this->assertNotContains((string) $this->developer->id, $users);
        }

        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $this->admin->id, 'permission_key' => PortalRules::DEVELOPERS_ACTIVITY, 'reach' => null]);
        $this->assertContains($this->developer->id, $this->people('default', $this->admin));
        $this->assertContains($this->developer->id, $this->people('dev', $this->otherDeveloper));
    }

    public function test_only_mis_admins_and_executives_hold_it_and_nobody_gets_it_with_all_logs(): void
    {
        $this->change('dev', $this->developer, $this->engineeringAdmin, ['key' => PortalRules::DEVELOPERS_ACTIVITY, 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'Only executives and MIS administrators can hold that power.');
        $engineeringSheet = collect($this->asMode('dev', $this->developer)->getJson("/api/development/portal/manage/access/{$this->engineeringAdmin->id}")->assertOk()->json('data'))->pluck('key')->all();
        $this->assertNotContains(PortalRules::DEVELOPERS_ACTIVITY, $engineeringSheet);

        $this->change('dev', $this->developer, $this->admin, ['key' => 'administration.logs', 'action' => 'off'])->assertOk();
        $this->change('dev', $this->developer, $this->admin, ['key' => 'administration.logs', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->admin, 'administration.logs'));
        $this->assertNull($this->ownPower('default', $this->admin, PortalRules::DEVELOPERS_ACTIVITY));

        $this->change('dev', $this->developer, $this->admin, ['key' => PortalRules::DEVELOPERS_ACTIVITY, 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->admin, PortalRules::DEVELOPERS_ACTIVITY));
    }

    /**
     * @return list<int>
     */
    private function people(string $mode, Employee $viewer): array
    {
        $rows = $this->asMode($mode, $viewer)->getJson('/api/development/portal/administration/all-logs?per_page=100')->assertOk()->json('data');

        return collect($rows)->pluck('employeeId')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function latestSignIn(Employee $employee): int
    {
        return (int) DB::connection('portal')->table('logs')->where('employee_id', $employee->id)->where('resource', 'auth.login')->orderByDesc('id')->value('id');
    }
}
