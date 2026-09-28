<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FilesPortalReports;
use Tests\Concerns\ManagesPortalAccess;
use Tests\TestCase;

/**
 * Administration / All Actions names a developer's Developer mode writes the way All Logs does:
 * "A developer" to anyone not in Developer mode, "Name (Developer mode)" to those in it, and never
 * through the person filter or a name search. Their own work within Default stays theirs by name.
 */
class PortalAllActionsDeveloperTest extends TestCase
{
    use DatabaseTransactions;
    use FilesPortalReports;
    use ManagesPortalAccess;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private Employee $john;

    private Employee $nathaniel;

    private Employee $viewer;

    private Employee $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();

        $admins = "LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'";
        $this->john = $this->activeWhere($admins.' AND id IN (SELECT employee_id FROM employees_projects)', 1)[0];
        [$this->nathaniel, $this->viewer] = $this->activeWhere($admins.' AND id != '.(int) $this->john->id, 2);
        $this->member = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 1)[0];
        $this->john->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->nathaniel->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->viewer->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->member->forceFill(['is_developer' => false, 'department' => 'MIS', 'role' => 'User'])->save();
    }

    public function test_a_developer_mode_write_on_someone_else_is_marked_and_named_by_the_viewer_s_mode(): void
    {
        $path = "/api/development/portal/manage/users/{$this->member->id}/role";
        $this->asMode('dev', $this->john)->patchJson($path, ['role' => 'Admin'])->assertOk();
        $masked = $this->latestAction($this->john);
        $this->asMode('default', $this->john)->patchJson($path, ['role' => 'User'])->assertOk();
        $named = $this->latestAction($this->john);
        $this->assertTrue((bool) $masked->as_developer);
        $this->assertFalse((bool) $named->as_developer);
        $name = PortalActivityCopy::displayName($this->john);

        // Without See developers' activity nothing John did is there at all.
        $this->assertNull($this->rows('default', $this->viewer)->firstWhere('id', (string) $masked->id));
        $this->assertNull($this->rows('default', $this->viewer)->firstWhere('id', (string) $named->id));
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $this->viewer->id, 'permission_key' => PortalRules::DEVELOPERS_ACTIVITY, 'reach' => null]);

        $outside = $this->rows('default', $this->viewer);
        $hidden = $outside->firstWhere('id', (string) $masked->id);
        $this->assertSame('A developer', $hidden['employeeName'] ?? null);
        $this->assertNull($hidden['employeeId']);
        $this->assertNull($hidden['employeeIdNo']);
        $this->assertNull($hidden['recycleKey']);
        $this->assertSame($name, $outside->firstWhere('id', (string) $named->id)['employeeName'] ?? null);
        $this->assertSame('A developer', $this->asMode('default', $this->viewer)->getJson("/api/development/portal/administration/all-actions/{$masked->id}")->assertOk()->json('data.employeeName'));

        // Neither the person filter nor a name search leads to it.
        $byPerson = $this->rows('default', $this->viewer, '&actor_id='.$this->john->id)->pluck('id')->all();
        $this->assertNotContains((string) $masked->id, $byPerson);
        $this->assertContains((string) $named->id, $byPerson);
        $byName = $this->rows('default', $this->viewer, '&q='.urlencode((string) $this->john->last_name))->pluck('id')->all();
        $this->assertNotContains((string) $masked->id, $byName);
        $this->assertContains((string) $named->id, $byName);

        $inside = $this->rows('dev', $this->nathaniel)->firstWhere('id', (string) $masked->id);
        $this->assertSame($name.' (Developer mode)', $inside['employeeName'] ?? null);
        $this->assertSame($this->john->id, $inside['employeeId']);
    }

    public function test_own_work_within_default_stays_named_in_developer_mode(): void
    {
        $date = $this->freeReportDate($this->john);
        $this->asMode('dev', $this->john)->postJson('/api/development/portal/reports/submitted', $this->dailyReportBody($this->john, $date))->assertCreated();

        $this->assertFalse((bool) $this->latestAction($this->john)->as_developer);
    }

    private function latestAction(Employee $actor): object
    {
        $row = DB::connection('core')->table('actions')->where('actor_id', $actor->id)->orderByDesc('id')->first();
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(string $mode, Employee $viewer, string $query = ''): Collection
    {
        return collect($this->asMode($mode, $viewer)->getJson('/api/development/portal/administration/all-actions?per_page=100'.$query)->assertOk()->json('data'));
    }
}
