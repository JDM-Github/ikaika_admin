<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Envelopes can now add and edit budget codes and envelopes and record funds received (nj,
 * merged 2026-10-01). Both become powers under Envelopes instead of the admin role, held by admins,
 * executives and developers as before. The same rows and presets live in sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const RULES = [
        ['finance.envelopes.edit', 'Add and edit', 'Add and change budget codes and envelopes, and close or reopen an envelope.', 856],
        ['finance.envelopes.funds', 'Record funds', 'Record money an envelope received, credited to it, its budget code and its account.', 857],
    ];

    private const ROLES = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'finance.envelopes')->exists()) {
            return;
        }
        foreach (self::RULES as [$key, $label, $description, $order]) {
            $portal->table('access_permissions')->insertOrIgnore([
                'permission_key' => $key,
                'parent_key' => 'finance.envelopes',
                'label' => $label,
                'description' => $description,
                'supports_reach' => 0,
                'can_toggle' => 1,
                'sort_order' => $order,
            ]);
            foreach (self::ROLES as $role) {
                $portal->table('access_presets')->insertOrIgnore([
                    'role' => $role,
                    'permission_key' => $key,
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

        foreach (self::RULES as [$key]) {
            DB::connection('portal')->table('access_permissions')->where('permission_key', $key)->delete();
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
