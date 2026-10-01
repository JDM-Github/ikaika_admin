<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Workspace\WorkspaceAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data workspace's grants are its four powers (workspace, .private, .edit, .views): admins and
 * executives hold them by preset, a member holds only what they are given, and the retired
 * ProjectAdmin role reads as a member. The tier is kept for the badge's label alone.
 */
class WorkspaceAccessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
    }

    private function employee(string $where): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function access(Employee $employee): WorkspaceAccess
    {
        return WorkspaceAccess::of($employee, Request::create('/'));
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool, 3: bool}
     */
    private function grants(WorkspaceAccess $access): array
    {
        return [$access->canBrowse(), $access->canReadPrivate(), $access->canEdit(), $access->canShareViews()];
    }

    public function test_admins_and_executives_hold_every_grant_by_preset(): void
    {
        $admin = $this->access($this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'"));
        $executive = $this->access($this->employee("LOWER(COALESCE(role_level, '')) = 'executive'"));

        $this->assertSame([true, true, true, true], $this->grants($admin));
        $this->assertSame('admin', $admin->tier());
        $this->assertSame([true, true, true, true], $this->grants($executive));
        $this->assertSame('executive', $executive->tier());
    }

    public function test_a_member_is_refused_with_somewhere_else_to_go(): void
    {
        $member = $this->access($this->employee("LOWER(COALESCE(role, '')) = 'user'"));

        $this->assertSame([false, false, false, false], $this->grants($member));
        $this->expectExceptionMessage('The data workspace needs the Data Workspace power. Your work lives in the portal.');
        $member->assertCanBrowse();
    }

    // Given only Workspace, a member browses with private columns locked, keeps private views, edits nothing.
    public function test_a_member_given_the_workspace_browses_and_nothing_more(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'workspace', 'reach' => null]);

        $this->assertSame([
            'tier' => 'member',
            'label' => 'Member',
            'browse' => true,
            'edit' => false,
            'private' => false,
            'share_views' => false,
        ], $this->access($member)->grants());
    }

    public function test_switching_edit_cells_off_takes_editing_alone(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'workspace.edit']);
        $access = $this->access($admin);

        $this->assertSame([true, true, false, true], $this->grants($access));
        $this->expectExceptionMessage('Editing the databases directly needs the Edit cells power.');
        $access->assertCanEdit();
    }

    // The ProjectAdmin role is retired and reads as a member: no workspace unless someone gives it.
    public function test_a_project_admin_reads_as_a_member(): void
    {
        $projectAdmin = $this->employee("LOWER(COALESCE(role, '')) = 'projectadmin'");
        $access = $this->access($projectAdmin);

        $this->assertSame('project-admin', $access->tier());
        $this->assertSame('Project admin', $access->grants()['label']);
        $this->assertFalse($access->canBrowse());
    }
}
