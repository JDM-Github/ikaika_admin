<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generate ID under Manage / Users (2026-10-14, user): the person's ID card, front and back, opened
 * from their row. It prints their blood type and emergency contact, so it is its own power rather
 * than Show employee information; admins, executives and developers hold it by preset. Mirrored in
 * sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const KEY = 'manage.users.id_card';

    private const ROLES = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'manage.users')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'manage.users',
            'label' => 'Generate ID',
            'description' => 'Make the ID card of someone in {UserName|Your} reach, front and back, with their blood type and emergency contact as it prints. Download it as PDF, PNG or Photoshop files.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 852,
        ]);
        foreach (self::ROLES as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => self::KEY,
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
        DB::connection('portal')->table('access_presets')->where('permission_key', self::KEY)->delete();
        DB::connection('portal')->table('access_permissions')->where('permission_key', self::KEY)->delete();
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
