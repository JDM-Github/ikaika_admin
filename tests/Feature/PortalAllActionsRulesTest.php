<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Portal\PortalRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesPortalAccess;
use Tests\TestCase;

/**
 * Administration / All Actions follows administration.actions: only MIS administrators (and
 * executives or developers given it) hold it, admins start at every department, executives' changes
 * need its executives flag, and nothing a developer changed shows without See developers' activity.
 * Sync to Airtable is its own power, for MIS administrators only.
 */
class PortalAllActionsRulesTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private Employee $misAdmin;

    private Employee $engineeringAdmin;

    private Employee $developer;

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
        [$this->misAdmin, $this->engineeringAdmin, $this->developer] = $this->activeWhere($admins, 3);
        [$this->member, $this->outsider] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 2);
        $this->executive = $this->activeWhere("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];
        $this->misAdmin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->engineeringAdmin->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->member->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->outsider->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        $this->executive->forceFill(['is_developer' => false])->save();
    }

    public function test_only_mis_administrators_open_all_actions(): void
    {
        $this->asMode('default', $this->misAdmin)->getJson('/api/development/portal/administration/all-actions')->assertOk();
        foreach ([$this->engineeringAdmin, $this->member] as $person) {
            $this->asMode('default', $person)->getJson('/api/development/portal/administration/all-actions')
                ->assertForbidden()->assertJsonPath('message', PortalRules::REFUSAL);
        }
        $this->assertNull($this->ownPower('default', $this->engineeringAdmin, 'administration.actions'));
        $this->assertSame('all', $this->ownPower('default', $this->misAdmin, 'administration.actions')['reach'] ?? null);
    }

    // Every department by default, never an executive without the flag, and changes made by nobody too.
    public function test_mis_admins_see_every_department_but_not_executives_or_developers(): void
    {
        $outsider = $this->action($this->outsider);
        $executive = $this->action($this->executive);
        $developer = $this->action($this->developer);
        $system = $this->action(null);

        $seen = $this->ids('default', $this->misAdmin);
        $this->assertContains($outsider, $seen);
        $this->assertContains($system, $seen);
        $this->assertNotContains($executive, $seen);
        $this->assertNotContains($developer, $seen);
        $this->asMode('default', $this->misAdmin)->getJson("/api/development/portal/administration/all-actions/{$developer}")->assertNotFound();
        $people = $this->asMode('default', $this->misAdmin)->getJson('/api/development/portal/administration/all-actions?per_page=100')->json('filters.actors.*.value');
        $this->assertNotContains((string) $this->developer->id, $people);

        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $this->misAdmin->id, 'permission_key' => PortalRules::DEVELOPERS_ACTIVITY, 'reach' => null]);
        $this->assertContains($developer, $this->ids('default', $this->misAdmin));
    }

    // Syncing is its own power: MIS administrators may hold it, nobody starts with it by role.
    public function test_sync_to_airtable_stays_with_mis_administrators(): void
    {
        $this->assertNull($this->ownPower('default', $this->misAdmin, 'administration.actions.sync'));
        $this->change('dev', $this->developer, $this->misAdmin, ['key' => 'administration.actions.sync', 'action' => 'on'])->assertOk();
        $this->assertNotNull($this->ownPower('default', $this->misAdmin, 'administration.actions.sync'));
        $this->change('dev', $this->developer, $this->engineeringAdmin, ['key' => 'administration.actions.sync', 'action' => 'on'])
            ->assertStatus(422)->assertJsonPath('message', 'Only executives and MIS administrators can hold that power.');
    }

    /**
     * @return list<int>
     */
    private function ids(string $mode, Employee $viewer): array
    {
        $rows = $this->asMode($mode, $viewer)->getJson('/api/development/portal/administration/all-actions?per_page=100')->assertOk()->json('data');

        return collect($rows)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function action(?Employee $actor): int
    {
        return (int) DB::connection('core')->table('actions')->insertGetId([
            'product' => CoreLedger::PRODUCT_PORTAL,
            'database_target' => 'portal.employees',
            'action_type' => 'edit',
            'resource' => 'employees',
            'record_id' => (string) ($actor?->id ?? 0),
            'recycle_key' => null,
            'actor_id' => $actor?->id,
            'actor_id_no' => $actor?->id_no,
            'as_developer' => false,
            'parameters' => json_encode(['parameters' => ['fields' => ['note' => 'test']]], JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now(),
        ]);
    }
}
