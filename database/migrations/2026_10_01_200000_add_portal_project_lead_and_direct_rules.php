<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step C of docs/power/view-projects-plan.md (2026-10-01): Add projects gains a reach (which departments
 * a project may be started for), Project Lead says who may be chosen as lead and for which departments,
 * and Add without approval skips the wait for a Project Manager. The same rows live in
 * sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const LEADERS = ['admin' => 'all', 'executive' => 'all', 'developer' => 'all', 'member' => 'department'];

    // Adding stays open to every department for everyone who holds it, as it was before it had a reach.
    private const ADDERS = ['admin', 'executive', 'developer', 'member'];

    private const DIRECT = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        $portal->table('access_permissions')->where('permission_key', 'projects.create')->update([
            'supports_reach' => 1,
            'description' => "Start a new project from Add New Project, for {UserName|Your} department or every department.",
        ]);
        $portal->table('access_presets')->where('permission_key', 'projects.create')->whereIn('role', self::ADDERS)->update(['reach' => 'all']);
        $portal->table('access_personal')->where('permission_key', 'projects.create')->whereNull('reach')->update(['reach' => 'all']);
        $portal->table('access_grants')->where('permission_key', 'projects.create')->whereNull('reach')->update(['reach' => 'all']);

        $portal->table('access_permissions')->insertOrIgnore([
            [
                'permission_key' => 'projects.lead',
                'parent_key' => 'projects.view',
                'label' => 'Project Lead',
                'description' => "Be chosen as the lead of {UserName|Your} department's projects or of any project.",
                'supports_reach' => 1,
                'can_toggle' => 1,
                'sort_order' => 214,
            ],
            [
                'permission_key' => 'projects.create.direct',
                'parent_key' => 'projects.create',
                'label' => 'Add without approval',
                'description' => "A project {UserName|You} add is approved at once, without waiting for a Project Manager.",
                'supports_reach' => 0,
                'can_toggle' => 1,
                'sort_order' => 221,
            ],
        ]);
        foreach (self::LEADERS as $role => $reach) {
            $portal->table('access_presets')->insertOrIgnore(['role' => $role, 'permission_key' => 'projects.lead', 'reach' => $reach, 'include_executives' => 0]);
        }
        foreach (self::DIRECT as $role) {
            $portal->table('access_presets')->insertOrIgnore(['role' => $role, 'permission_key' => 'projects.create.direct', 'reach' => null, 'include_executives' => 0]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->whereIn('permission_key', ['projects.lead', 'projects.create.direct'])->delete();
        $portal->table('access_permissions')->where('permission_key', 'projects.create')->update([
            'supports_reach' => 0,
            'description' => 'Start a new project from Add New Project.',
        ]);
        $portal->table('access_presets')->where('permission_key', 'projects.create')->update(['reach' => null]);
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
