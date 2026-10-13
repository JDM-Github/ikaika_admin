<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activate and deactivate under Manage / Users (2026-10-12, user): an admin turns someone in their
 * Users reach Active or Inactive, never themselves or an executive, and a fellow admin only with
 * Manage other admins. Admins, executives and developers hold it by preset. Mirrored in
 * sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const KEY = 'manage.users.status';

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
            'label' => 'Activate and deactivate',
            'description' => 'Turn the account of someone in {UserName|Your} reach active or inactive. An inactive account cannot sign in.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 851,
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
