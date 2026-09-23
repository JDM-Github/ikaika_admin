<?php

namespace Tests\Unit;

use App\Modules\Portal\Models\Employee;
use App\Support\Workspace\WorkspaceAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string, 3: bool, 4: bool, 5: bool}>
     */
    public static function tiers(): array
    {
        //                            role            level         tier             browse  private  edit
        return [
            'executive by level' => ['Admin', 'Executive', 'executive', true, true, true],
            'executive by role' => ['Executive', null, 'executive', true, true, true],
            'admin' => ['Admin', 'Standard', 'admin', true, true, true],
            'admin lowercase' => ['admin', null, 'admin', true, true, true],
            'project admin' => ['ProjectAdmin', null, 'project-admin', true, false, false],
            'member' => ['User', null, 'member', false, false, false],
            'no role at all' => [null, null, 'member', false, false, false],
            'invented role' => ['Superuser', null, 'member', false, false, false],
        ];
    }

    #[DataProvider('tiers')]
    public function test_a_role_resolves_to_one_tier_of_grants(
        ?string $role,
        ?string $level,
        string $tier,
        bool $browse,
        bool $private,
        bool $edit,
    ): void {
        $access = WorkspaceAccess::of($this->employee($role, $level));

        $this->assertSame($tier, $access->tier());
        $this->assertSame($browse, $access->canBrowse());
        $this->assertSame($private, $access->canReadPrivate());
        $this->assertSame($edit, $access->canEdit());

        // Sharing a view with everyone is an administrator's call, like editing.
        $this->assertSame($edit, $access->canShareViews());
    }

    public function test_grants_state_the_boundary_the_page_shows(): void
    {
        $grants = WorkspaceAccess::of($this->employee('ProjectAdmin', null))->grants();

        $this->assertSame([
            'tier' => 'project-admin',
            'label' => 'Project admin',
            'browse' => true,
            'edit' => false,
            'private' => false,
            'share_views' => false,
        ], $grants);
    }

    public function test_a_member_is_refused_with_somewhere_else_to_go(): void
    {
        $this->expectExceptionMessage('The data workspace is for administrators. Your work lives in the portal.');

        WorkspaceAccess::of($this->employee('User', null))->assertCanBrowse();
    }

    public function test_a_project_admin_is_refused_editing_specifically(): void
    {
        $this->expectExceptionMessage('Editing the databases directly is limited to administrators.');

        WorkspaceAccess::of($this->employee('ProjectAdmin', null))->assertCanEdit();
    }

    private function employee(?string $role, ?string $level): Employee
    {
        $employee = new Employee;
        $employee->id = 7;
        $employee->id_no = '220302-0003';
        $employee->role = $role;
        $employee->role_level = $level;

        return $employee;
    }
}
