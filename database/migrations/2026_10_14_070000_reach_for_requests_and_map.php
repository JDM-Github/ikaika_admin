<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / Requests and Manage / Map become reach rules (2026-10-14, user): the Access page offers
 * their reach, the shared-pages form offers it too, and each page follows it. Requests mirrors its
 * kinds (admin at department, developers and executives at all); the map starts at every department
 * for admins, which is what it showed before, and a department reach now narrows it. Also in
 * sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const REACH_RULES = ['manage.requests', 'manage.map'];

    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const PRESETS = [
        ['admin', 'manage.requests', 'department'],
        ['developer', 'manage.requests', 'all'],
        ['executive', 'manage.requests', 'all'],
        ['admin', 'manage.map', 'all'],
        ['developer', 'manage.map', 'all'],
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->whereIn('permission_key', self::REACH_RULES)->update(['supports_reach' => 1]);
        foreach (self::PRESETS as [$role, $key, $reach]) {
            $portal->table('access_presets')->updateOrInsert(
                ['role' => $role, 'permission_key' => $key],
                ['reach' => $reach, 'include_executives' => 0],
            );
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        DB::connection('portal')->table('access_permissions')->whereIn('permission_key', self::REACH_RULES)->update(['supports_reach' => 0]);
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
