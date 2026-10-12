<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Created Projects becomes its own power (2026-10-09, user): the page used to follow Add projects
 * (projects.create), so it vanished for anyone barred from adding - even though the record of what
 * they already submitted is still theirs. It is now projects.created, with no reach (it is only
 * ever the person's own list), held by every role by preset; GET projects/mine follows it.
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

        // A child needs its parent row: Projects may not have been deployed everywhere yet.
        if (! $portal->table('access_permissions')->where('permission_key', 'projects')->exists()) {
            return;
        }
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => 'projects.created',
            'parent_key' => 'projects',
            'label' => 'Created Projects',
            'description' => 'See the projects {UserName|You} added and where their approval stands.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => null,
            'sort_order' => 225,
        ]);

        // Every role holds it by preset, as the page did under Add projects.
        foreach (['member', 'admin', 'executive', 'developer'] as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => 'projects.created',
                'reach' => null,
                'include_executives' => 0,
            ]);
        }

        // Existing shares followed Add projects for the Created Projects page; move them with it.
        if (Schema::connection('portal')->hasTable('shared_page_shareable')) {
            $portal->table('shared_page_shareable')->where('rule_key', 'projects.create')->update(['rule_key' => 'projects.created']);
        }
        if (Schema::connection('portal')->hasTable('shared_page_rules')) {
            $portal->table('shared_page_rules')->where('rule_key', 'projects.create')->update(['rule_key' => 'projects.created']);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        if (Schema::connection('portal')->hasTable('shared_page_shareable')) {
            $portal->table('shared_page_shareable')->where('rule_key', 'projects.created')->update(['rule_key' => 'projects.create']);
        }
        if (Schema::connection('portal')->hasTable('shared_page_rules')) {
            $portal->table('shared_page_rules')->where('rule_key', 'projects.created')->update(['rule_key' => 'projects.create']);
        }

        $portal->table('access_presets')->where('permission_key', 'projects.created')->delete();
        $portal->table('access_permissions')->where('permission_key', 'projects.created')->delete();
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
