<?php

namespace Tests\Unit;

use App\Support\Portal\PortalRole;
use Tests\TestCase;

class PortalRoleTest extends TestCase
{
    public function test_executive_is_the_highest_admin_and_is_locked(): void
    {
        $this->assertTrue(PortalRole::isExecutive('Admin', 'Executive'));
        $this->assertTrue(PortalRole::isAdmin('Admin', 'Executive'));
        $this->assertTrue(PortalRole::canManageUsers('Admin', 'Executive'));
        $this->assertTrue(PortalRole::isLocked('Admin', 'Executive'));
    }

    public function test_admin_can_manage_users_and_is_not_locked(): void
    {
        $this->assertTrue(PortalRole::isAdmin('Admin', 'Management'));
        $this->assertTrue(PortalRole::canManageUsers('Admin', null));
        $this->assertFalse(PortalRole::isLocked('Admin', 'Management'));
        $this->assertFalse(PortalRole::isExecutive('Admin', 'Management'));
    }

    public function test_user_and_project_admin_cannot_manage_users(): void
    {
        $this->assertFalse(PortalRole::canManageUsers('User', 'Production/Technical'));
        $this->assertFalse(PortalRole::isAdmin('ProjectAdmin', 'Project Leadership'));
        $this->assertFalse(PortalRole::isLocked('User', null));
    }

    public function test_assignable_roles_include_project_admin(): void
    {
        $this->assertSame(['Admin', 'User', 'ProjectAdmin'], PortalRole::assignableRoles());
    }

    public function test_moving_an_admin_to_project_admin_removes_admin_rights(): void
    {
        $this->assertTrue(PortalRole::isRemovingAdminRights('Admin', 'Management', PortalRole::PROJECT_ADMIN));
        $this->assertTrue(PortalRole::isRemovingAdminRights('Admin', null, PortalRole::USER));
        $this->assertFalse(PortalRole::isRemovingAdminRights('User', null, PortalRole::PROJECT_ADMIN));
        $this->assertFalse(PortalRole::isRemovingAdminRights('Admin', null, PortalRole::ADMIN));
    }

    public function test_role_change_refuses_executive_self_demote_and_last_admin(): void
    {
        $this->assertSame(
            'The executive role cannot be changed.',
            PortalRole::roleChangeBlock(1, 2, true, true, true, 5),
        );
        $this->assertSame(
            'You cannot remove your own administrator role.',
            PortalRole::roleChangeBlock(4, 4, false, true, true, 3),
        );
        $this->assertSame(
            'Someone has to keep the keys. Promote another member before removing this one.',
            PortalRole::roleChangeBlock(1, 2, false, true, true, 1),
        );
        $this->assertNull(PortalRole::roleChangeBlock(1, 2, false, false, false, 2));
    }
}
