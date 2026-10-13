<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edit transactions under Finance / Transactions (2026-10-13, user): a transaction's name, date,
 * payor or payee, notes and receipt, never its amount, type, envelope or account. Held by admins,
 * executives and developers like the rest of Finance. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'finance.transactions.edit';

    private const ROLES = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'finance.transactions')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'finance.transactions',
            'label' => 'Edit transactions',
            'description' => 'Change a transaction\'s name, date, payor or payee, notes and receipt. Its amount, type, envelope and account stay as they were.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'sort_order' => 865,
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
