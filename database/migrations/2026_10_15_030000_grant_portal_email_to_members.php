<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email for every member (2026-10-15, user): the Email page is no longer an administrator's alone.
 * A member opens it preset to "Me" -- their own address, a signature footer -- and the page sits
 * before Bug Reports in the sidebar. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEYS = ['email', 'email.send'];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $portal = DB::connection('portal');
        foreach (self::KEYS as $key) {
            // A permission that was never seeded (an old database) is skipped by the foreign key, not inserted.
            if (! $portal->table('access_permissions')->where('permission_key', $key)->exists()) {
                continue;
            }
            $portal->table('access_presets')->insertOrIgnore([
                'role' => 'member',
                'permission_key' => $key,
                'reach' => null,
                'include_executives' => 0,
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        DB::connection('portal')->table('access_presets')
            ->where('role', 'member')->whereIn('permission_key', self::KEYS)->delete();
    }

    private function portalReachable(): bool
    {
        try {
            Schema::connection('portal')->getConnection()->getPdo();
        } catch (Throwable) {
            return false;
        }

        return Schema::connection('portal')->hasTable('access_presets');
    }
};
