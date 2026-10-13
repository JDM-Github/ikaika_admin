<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edit employee information under Show employee information (2026-10-12, user): the private details
 * of the people whose information someone already sees. Held by the same people who see it:
 * executives and developers by preset, and Nathaniel Tanglao and Steven Gaurano by their own
 * defaults. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'manage.users.information.edit';

    private const ROLES = ['executive', 'developer'];

    private const PERSONAL = [
        '220202-0002', // Nathaniel Tanglao
        '220302-0003', // Steven Gaurano
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'manage.users.information')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'manage.users.information',
            'label' => 'Edit employee information',
            'description' => 'Change the private details of the people whose information {UserName|You} can see. Airtable keeps its own copy.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 848,
        ]);
        foreach (self::ROLES as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => self::KEY,
                'reach' => null,
                'include_executives' => 0,
            ]);
        }
        foreach ($portal->table('employees')->whereIn('id_no', self::PERSONAL)->pluck('id') as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => (int) $id, 'permission_key' => self::KEY, 'reach' => null]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_personal')->where('permission_key', self::KEY)->delete();
        $portal->table('access_presets')->where('permission_key', self::KEY)->delete();
        $portal->table('access_permissions')->where('permission_key', self::KEY)->delete();
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
