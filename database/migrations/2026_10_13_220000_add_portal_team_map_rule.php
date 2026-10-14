<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / Map (2026-10-13, user): where each person last saved something. An administrator's power,
 * held by administrators and developers by preset; executives none. Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'manage.map';

    private const ROLES = ['admin', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'manage',
            'label' => 'Map',
            'description' => 'See on a world map where each person last saved something in the portal.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 880,
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
        $portal = DB::connection('portal');
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
