<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove scope assignees (2026-10-14, user): taking someone assigned to a project's scopes off its
 * Members or Support members, through the members-only edit, needs this power. Project Lead carries
 * it (PortalAccessGraph::IMPLIED); admins, executives and developers hold it by preset, members only
 * when given it. Mirrored in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        // A child needs its parent row: View Projects may not have been deployed everywhere yet.
        if (! $portal->table('access_permissions')->where('permission_key', 'projects.view')->exists()) {
            return;
        }
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => 'projects.assignees.remove',
            'parent_key' => 'projects.view',
            'label' => 'Remove scope assignees',
            'description' => 'Take someone assigned to a project\'s scopes off its Members or Support members. Comes with Project Lead.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => null,
            'sort_order' => 216,
        ]);

        foreach (['admin', 'executive', 'developer'] as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => 'projects.assignees.remove',
                'reach' => null,
                'include_executives' => 0,
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_presets')->where('permission_key', 'projects.assignees.remove')->delete();
        $portal->table('access_permissions')->where('permission_key', 'projects.assignees.remove')->delete();
    }

    private function portalReachable(): bool
    {
        try {
            Schema::connection('portal')->getConnection()->getPdo();
        } catch (Throwable) {
            return false;
        }

        return true;
    }
};
