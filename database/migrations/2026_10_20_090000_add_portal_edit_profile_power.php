<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edit profile (2026-10-20, user): changing one's own details, bank information and documents on
 * Profile is a power of its own, under User. Everyone who holds User holds it by preset, so nothing
 * changes until someone switches it off: a department's head may, for anyone below them and for a
 * fellow administrator in their department, and then that person cannot edit their information.
 */
return new class extends Migration
{
    private const KEY = 'user.profile';

    private const ROLES = ['member', 'admin', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'user')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'user',
            'label' => 'Edit profile',
            'description' => 'Change {UserName|your} own details, bank information and documents on Profile, for a head admin to approve.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => null,
            'sort_order' => 505,
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
        foreach (['access_limits', 'access_grants', 'access_personal', 'access_toggles', 'access_presets', 'access_permissions'] as $table) {
            if (Schema::connection('portal')->hasTable($table)) {
                $portal->table($table)->where('permission_key', self::KEY)->delete();
            }
        }
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
