<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edit, Manage Scopes and Delete projects (2026-10-01, docs/power/view-projects-plan.md): three reach
 * rules under View Projects in place of projects.view.edit and projects.view.delete. Own reach is the
 * projects the person leads, so members hold all three at own by preset: the lead exception is a real
 * rule a manager can limit and the person can switch off. The same rows live in sql/portal/separate.sql.
 */
return new class extends Migration
{
    // Old key => the new keys that inherit its grants, limits, switches and lists; edit used to cover scopes.
    private const RENAMES = [
        'projects.view.edit' => ['projects.edit', 'projects.scopes'],
        'projects.view.delete' => ['projects.delete'],
    ];

    private const TABLES = ['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'access_lists'];

    private const RULES = [
        ['projects.edit', 'Edit projects', "Edit the details of {UserName|Your} department's projects or every project. On Only your own, the projects {UserName|You} lead.", 211],
        ['projects.scopes', 'Manage scopes', "Choose the WBS scopes of {UserName|Your} department's projects or every project. On Only your own, the projects {UserName|You} lead.", 212],
        ['projects.delete', 'Delete projects', "Move {UserName|Your} department's projects or any project to the recycle bin. On Only your own, the projects {UserName|You} lead.", 213],
    ];

    // Who held the old flat rules keeps every project; members keep the projects they lead.
    private const PRESETS = ['admin' => 'all', 'executive' => 'all', 'developer' => 'all', 'member' => 'own'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        foreach (self::RULES as [$key, $label, $description, $order]) {
            $portal->table('access_permissions')->insertOrIgnore([
                'permission_key' => $key,
                'parent_key' => 'projects.view',
                'label' => $label,
                'description' => $description,
                'supports_reach' => 1,
                'can_toggle' => 1,
                'sort_order' => $order,
            ]);
            foreach (self::PRESETS as $role => $reach) {
                $portal->table('access_presets')->insertOrIgnore(['role' => $role, 'permission_key' => $key, 'reach' => $reach, 'include_executives' => 0]);
            }
        }

        foreach (self::RENAMES as $old => $news) {
            foreach (self::TABLES as $table) {
                foreach ($portal->table($table)->where('permission_key', $old)->get() as $row) {
                    $values = (array) $row;
                    unset($values['id']);
                    foreach ($news as $new) {
                        $portal->table($table)->insertOrIgnore([...$values, 'permission_key' => $new]);
                    }
                }
            }
            // Every row still on the old key, presets included, goes with it by cascade.
            $portal->table('access_permissions')->where('permission_key', $old)->delete();
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->insertOrIgnore([
            ['permission_key' => 'projects.view.edit', 'parent_key' => 'projects.view', 'label' => 'Edit projects', 'description' => "Edit project details and manage scopes. A project's lead can always edit their own project.", 'supports_reach' => 0, 'can_toggle' => 1, 'sort_order' => 211],
            ['permission_key' => 'projects.view.delete', 'parent_key' => 'projects.view', 'label' => 'Delete projects', 'description' => "Move a project to the recycle bin. A project's lead can always delete their own project.", 'supports_reach' => 0, 'can_toggle' => 1, 'sort_order' => 212],
        ]);
        foreach (['admin', 'executive', 'developer'] as $role) {
            foreach (array_keys(self::RENAMES) as $old) {
                $portal->table('access_presets')->insertOrIgnore(['role' => $role, 'permission_key' => $old, 'reach' => null, 'include_executives' => 0]);
            }
        }
        $portal->table('access_permissions')->whereIn('permission_key', array_column(self::RULES, 0))->delete();
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
