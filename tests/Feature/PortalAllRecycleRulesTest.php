<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ManagesPortalAccess;
use Tests\TestCase;

/**
 * Administration / All Recycle follows administration.recycle: its reach, the white and black lists
 * set on it, and its ignore power. The Access Rule sheet offers the lists and never the Access page.
 */
class PortalAllRecycleRulesTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesPortalAccess;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private Employee $admin;

    private Employee $developer;

    private Employee $viewer;

    private Employee $colleague;

    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();
        Recycle::query()->where('product', CoreLedger::PRODUCT_PORTAL)->delete();

        $admins = "LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'";
        [$this->admin, $this->developer] = $this->activeWhere($admins, 2);
        [$this->viewer, $this->colleague, $this->outsider] = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 3);
        $this->admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->viewer->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->colleague->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->outsider->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        foreach ([$this->admin, $this->viewer, $this->colleague, $this->outsider] as $index => $person) {
            $this->putInBin($person, '2026-08-1'.$index);
        }
    }

    public function test_all_recycle_follows_its_rule_and_reach(): void
    {
        // A member without the rule only ever gets their own bin, whatever they ask for.
        $this->assertSame([(string) $this->viewer->id], $this->deleters('default', $this->viewer));

        $this->assertEqualsCanonicalizing([(string) $this->admin->id, (string) $this->viewer->id, (string) $this->colleague->id], $this->deleters('default', $this->admin));
        $this->asMode('default', $this->admin)->patchJson('/api/development/portal/access/reach', ['key' => 'administration.recycle', 'reach' => 'own'])->assertOk();
        $this->assertSame([(string) $this->admin->id], $this->deleters('default', $this->admin));
    }

    // The lists narrow whoever holds the rule: every black list hides, every white list must name.
    public function test_white_and_black_lists_narrow_all_recycle_and_the_ignore_power_skips_them(): void
    {
        $this->change('default', $this->admin, $this->viewer, ['key' => 'administration.recycle', 'action' => 'on', 'reach' => 'department'])->assertOk();
        $this->assertTrue($this->sheetRule('default', $this->admin, $this->viewer, 'administration.recycle')['supportsList']);
        $this->assertEqualsCanonicalizing([(string) $this->admin->id, (string) $this->viewer->id, (string) $this->colleague->id], $this->deleters('default', $this->viewer));

        $this->setList('default', $this->admin, $this->viewer, 'block', [$this->colleague], 'administration.recycle')->assertOk();
        $this->assertEqualsCanonicalizing([(string) $this->admin->id, (string) $this->viewer->id], $this->deleters('default', $this->viewer));

        $this->setList('default', $this->admin, $this->viewer, 'allow', [$this->colleague], 'administration.recycle')->assertOk();
        $this->assertEqualsCanonicalizing([(string) $this->viewer->id, (string) $this->colleague->id], $this->deleters('default', $this->viewer));
        $this->assertSame(404, $this->asMode('default', $this->viewer)->getJson('/api/development/portal/administration/recycle-bin/'.$this->binId($this->admin))->status());

        // Only a developer can give the ignore power: admins start without it.
        $this->change('dev', $this->developer, $this->viewer, ['key' => 'administration.recycle.unlisted', 'action' => 'on'])->assertOk();
        $this->assertEqualsCanonicalizing([(string) $this->admin->id, (string) $this->viewer->id, (string) $this->colleague->id], $this->deleters('default', $this->viewer));
    }

    // The Access page always stays on for everyone, so the sheet neither shows it nor lets anyone limit it.
    public function test_the_access_rule_sheet_leaves_out_the_access_page(): void
    {
        $keys = collect($this->asMode('default', $this->admin)->getJson("/api/development/portal/manage/access/{$this->viewer->id}")->assertOk()->json('data'))->pluck('key')->all();

        $this->assertNotContains('access', $keys);
        $this->assertContains('home', $keys);
        $this->change('default', $this->admin, $this->viewer, ['key' => 'access', 'action' => 'off'])
            ->assertStatus(422)->assertJsonPath('message', 'That power always stays on, so nobody can give or limit it.');
    }

    /**
     * @return list<string>
     */
    private function deleters(string $mode, Employee $viewer): array
    {
        $response = $this->asMode($mode, $viewer)->getJson('/api/development/portal/administration/recycle-bin?scope=all&per_page=100')->assertOk();

        return collect($response->json('data'))->pluck('employeeId')->map(static fn ($id): string => (string) $id)->unique()->values()->all();
    }

    private function binId(Employee $employee): int
    {
        return (int) Recycle::query()->where('product', CoreLedger::PRODUCT_PORTAL)->where('deleted_by', $employee->id)->value('id');
    }

    private function putInBin(Employee $employee, string $date): void
    {
        $recordId = $date.'-daily';
        Recycle::query()->insert([
            'product' => CoreLedger::PRODUCT_PORTAL,
            'recycle_key' => CoreRecycleKey::submittedReport((int) $employee->id, $date, 'daily'),
            'database_target' => 'portal.user_reports',
            'resource' => 'reports.submitted',
            'record_id' => $recordId,
            'payload' => json_encode(['id' => $recordId, 'kind' => 'daily', 'submittedOn' => $date, 'title' => 'Daily Report', 'lines' => []], JSON_THROW_ON_ERROR),
            'deleted_by' => (int) $employee->id,
            'deleted_by_id_no' => $employee->id_no,
            'purges_at' => Carbon::now()->addDays(30),
            'created_at' => Carbon::now(),
        ]);
    }
}
