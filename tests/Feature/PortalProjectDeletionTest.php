<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FilesPortalReports;
use Tests\TestCase;

/**
 * Deleting a project follows Delete projects (projects.view.delete): admins, executives and developers
 * hold it by preset and Developer mode always, while a project's lead may delete their own without
 * it. Nobody else may, whatever their role.
 */
class PortalProjectDeletionTest extends TestCase
{
    use DatabaseTransactions;
    use FilesPortalReports;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        DB::connection('portal')->table('access_toggles')->where('permission_key', 'like', 'projects%')->delete();
        $this->projectId = (int) DB::connection('portal')->table('projects')->whereNull('deleted_at')->orderBy('id')->value('id');
        // Nobody below leads it unless a test says so.
        DB::connection('portal')->table('projects')->where('id', $this->projectId)->update(['project_lead_email' => 'nobody@ikaika.test']);
    }

    private function employee(string $role): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw('LOWER(COALESCE(role, \'\')) = ?', [$role])
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    private function deleteAs(string $token): TestResponse
    {
        return $this->withToken($token)->deleteJson('/api/development/portal/projects/'.$this->projectId);
    }

    private function isDeleted(): bool
    {
        return DB::connection('portal')->table('projects')->where('id', $this->projectId)->whereNotNull('deleted_at')->exists();
    }

    public function test_an_admin_with_the_power_deletes_a_project(): void
    {
        $admin = $this->employee('admin');
        $this->deleteAs($this->token($admin))->assertSuccessful();

        $this->assertTrue($this->isDeleted());
        $trail = DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $this->projectId)
            ->orderByDesc('project_action_history.id')
            ->first(['project_action_history.action_name', 'project_action_history.created_by']);
        $this->assertSame('Project Deleted', $trail?->action_name);
        $this->assertSame(trim($admin->first_name.' '.$admin->last_name), $trail?->created_by);
    }

    // A deleted project leaves the report picker and Home, and nothing new may be filed against it.
    public function test_a_deleted_project_leaves_the_pickers_and_home_and_takes_no_reports(): void
    {
        $member = $this->employee('user');
        $projectId = (int) DB::connection('portal')->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $member->getKey())
            ->whereNull('projects.deleted_at')
            ->whereNotNull('projects.project_name')
            ->where('projects.status', 'Started')
            ->orderBy('projects.id')
            ->value('projects.id');
        $this->assertGreaterThan(0, $projectId);
        // The filing helper uses the member's first project, so make this one it.
        DB::connection('portal')->table('employees_projects')->where('employee_id', $member->getKey())->where('project_id', '!=', $projectId)->delete();
        $token = $this->token($member);
        $body = $this->dailyReportBody($member, $this->freeReportDate($member));
        $activeBefore = (int) $this->withToken($token)->getJson('/api/development/portal/home')->assertOk()->json('data.activeProjectCount');

        DB::connection('portal')->table('projects')->where('id', $projectId)->update(['deleted_at' => now()]);
        Cache::flush();

        $picker = $this->withToken($token)->getJson('/api/development/portal/reports/projects')->assertOk()->json('data');
        $this->assertNotContains((string) $projectId, array_column($picker, 'id'));
        $this->assertSame($activeBefore - 1, (int) $this->withToken($token)->getJson('/api/development/portal/home')->assertOk()->json('data.activeProjectCount'));
        $this->withToken($token)->postJson('/api/development/portal/reports/submitted', $body)->assertStatus(422);
    }

    // Restoring from the Recycle Bin clears the stamp and adds its own Trail history row.
    public function test_restoring_a_project_brings_it_back_with_a_trail_row(): void
    {
        $token = $this->token($this->employee('admin'));
        $this->deleteAs($token)->assertSuccessful();
        $recycleId = (int) DB::connection('core')->table('recycle')->where('recycle_key', 'project-'.$this->projectId)->value('id');

        $this->withToken($token)->postJson('/api/development/portal/administration/recycle-bin/'.$recycleId.'/restore')->assertSuccessful();

        $this->assertFalse($this->isDeleted());
        $this->assertSame('Project Restored', DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $this->projectId)
            ->orderByDesc('project_action_history.id')
            ->value('project_action_history.action_name'));
    }

    // Switched off, the power is gone even for an admin: the role alone no longer deletes.
    public function test_an_admin_without_the_power_is_refused(): void
    {
        $admin = $this->employee('admin');
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.view.delete']);

        $this->deleteAs($this->token($admin))
            ->assertForbidden()
            ->assertJsonPath('message', 'Deleting a project needs the Delete projects power, unless you lead it.');
        $this->assertFalse($this->isDeleted());
    }

    public function test_a_member_deletes_only_a_project_they_lead(): void
    {
        $member = $this->employee('user');
        $token = $this->token($member);

        $this->deleteAs($token)->assertForbidden();
        $this->assertFalse($this->isDeleted());

        DB::connection('portal')->table('projects')->where('id', $this->projectId)->update(['project_lead_email' => strtoupper((string) $member->email)]);
        $this->deleteAs($token)->assertSuccessful();
        $this->assertTrue($this->isDeleted());
    }

    // Developer mode holds every power, so a developer who is a member by role deletes in it.
    public function test_a_developer_in_developer_mode_deletes(): void
    {
        $member = $this->employee('user');
        $member->forceFill(['is_developer' => true])->save();
        $token = $this->developerModeOn($this->token($member));

        $this->deleteAs($token)->assertSuccessful();
        $this->assertTrue($this->isDeleted());
    }
}
