<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote candidates under Manage / Onboarding (2026-10-12, user): only the head admins, Nathaniel
 * Tanglao and Steven Gaurano, turn a submitted candidate into an employee, whose account is active at
 * once. Their own defaults, matched on id_no like is_developer, and Developer mode's like every rule;
 * no role holds it by preset. Create and revoke no longer promotes. Mirrored in
 * sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const KEY = 'manage.onboarding.promote';

    private const HEAD_ADMINS = [
        '220202-0002', // Nathaniel Tanglao
        '220302-0003', // Steven Gaurano
    ];

    private const MANAGE_KEY = 'manage.onboarding.manage';

    private const MANAGE_BEFORE = 'Create a candidate entry, change it, revoke it, and promote a submitted one.';

    private const MANAGE_AFTER = 'Create a candidate entry, change it and revoke it.';

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'manage.onboarding')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'manage.onboarding',
            'label' => 'Promote candidates',
            'description' => 'Turn a candidate who submitted their requirements into an employee on the work email given, able to sign in at once.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 873,
        ]);
        $portal->table('access_presets')->insertOrIgnore([
            'role' => 'developer',
            'permission_key' => self::KEY,
            'reach' => null,
            'include_executives' => 0,
        ]);
        foreach ($this->headAdminIds() as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => $id, 'permission_key' => self::KEY, 'reach' => null]);
        }
        $portal->table('access_permissions')->where('permission_key', self::MANAGE_KEY)->update(['description' => self::MANAGE_AFTER]);
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
        $portal->table('access_permissions')->where('permission_key', self::MANAGE_KEY)->update(['description' => self::MANAGE_BEFORE]);
    }

    /** @return list<int> */
    private function headAdminIds(): array
    {
        return DB::connection('portal')->table('employees')->whereIn('id_no', self::HEAD_ADMINS)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
