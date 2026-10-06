<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two powers under Manage / Users (2026-10-06): Add users puts someone in the portal before Airtable
 * has them, and Change departments sets the department the portal files someone under. Like Change
 * roles they are an administrator's, and stay with MIS among admins; each role that starts with
 * Change roles starts with them. The same rows live in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const RULES = [
        [
            'permission_key' => 'manage.users.add',
            'parent_key' => 'manage.users',
            'label' => 'Add users',
            'description' => 'Add someone to the portal before Airtable has them, in {UserName|Your} department or any department.',
            'sort_order' => 847,
        ],
        [
            'permission_key' => 'manage.users.department',
            'parent_key' => 'manage.users',
            'label' => 'Change departments',
            'description' => 'Move someone to another department: the one their reaches and the projects they may lead follow.',
            'sort_order' => 850,
        ],
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $roles = $portal->table('access_presets')->where('permission_key', 'manage.users.role')->pluck('role')->all();

        foreach (self::RULES as $rule) {
            $portal->table('access_permissions')->insertOrIgnore([
                ...$rule,
                'supports_reach' => 0,
                'can_toggle' => 1,
                'min_tier' => 'admin',
                'only_department' => 'MIS',
            ]);
            foreach ($roles as $role) {
                $portal->table('access_presets')->insertOrIgnore([
                    'role' => $role,
                    'permission_key' => $rule['permission_key'],
                    'reach' => null,
                    'include_executives' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $keys = array_column(self::RULES, 'permission_key');
        DB::connection('portal')->table('access_presets')->whereIn('permission_key', $keys)->delete();
        DB::connection('portal')->table('access_permissions')->whereIn('permission_key', $keys)->delete();
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
