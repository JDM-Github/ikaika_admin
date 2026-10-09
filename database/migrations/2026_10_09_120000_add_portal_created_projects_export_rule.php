<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Created Projects gains an Export key (2026-10-09, user): the table's export menu follows its own
 * power, the way Track Projects' does, so it can be limited without hiding the page. No reach - it
 * is only ever the person's own list. Every role holds it by preset, exactly today's visibility.
 * Mirrored in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        // A child needs its parent row: Created Projects may not have been deployed everywhere yet.
        if (! $portal->table('access_permissions')->where('permission_key', 'projects.created')->exists()) {
            return;
        }
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => 'projects.created.export',
            'parent_key' => 'projects.created',
            'label' => 'Export',
            'description' => 'Download the projects {UserName|You} added as PDF, XLSX, Word, CSV or JSON.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => null,
            'sort_order' => 226,
        ]);

        // Every role holds it by preset, as the export menu did under the page itself.
        foreach (['member', 'admin', 'executive', 'developer'] as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => 'projects.created.export',
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

        $portal->table('access_presets')->where('permission_key', 'projects.created.export')->delete();
        $portal->table('access_permissions')->where('permission_key', 'projects.created.export')->delete();
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
