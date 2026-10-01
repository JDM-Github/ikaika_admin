<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Deleting a project follows Delete projects (projects.view.delete): admins, executives and developers
 * hold it by preset and Developer mode always, while a project's lead may delete their own without
 * it. Nobody else may, whatever their role.
 */
class PortalProjectDeletionTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->deleteAs($this->token($this->employee('admin')))->assertSuccessful();

        $this->assertTrue($this->isDeleted());
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
