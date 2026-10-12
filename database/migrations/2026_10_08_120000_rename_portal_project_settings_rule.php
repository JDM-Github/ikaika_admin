<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Manage / Project Settings power is renamed with the page, which the user renamed References
 * (2026-10-08). Every row that held projects.settings moves to projects.references, so nobody loses
 * the page. Mirrored in separate.sql.
 *
 * The child tables point at access_permissions.permission_key, and the key is a parent on update, so
 * the new catalogue row is written first, the holders move to it, and only then does the old row go.
 */
return new class extends Migration
{
    private const FROM = 'projects.settings';

    private const TO = 'projects.references';

    /** The tables that carry a permission_key and point at the catalogue. */
    private const HOLDERS = ['access_presets', 'access_personal', 'access_grants', 'access_limits'];

    public function up(): void
    {
        $this->rename(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->rename(self::TO, self::FROM);
    }

    private function rename(string $from, string $to): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        $permission = $portal->table('access_permissions')->where('permission_key', $from)->first();
        if ($permission === null) {
            return;
        }

        $copy = (array) $permission;
        $copy['permission_key'] = $to;
        $portal->table('access_permissions')->insert($copy);

        foreach (self::HOLDERS as $table) {
            if (Schema::connection('portal')->hasTable($table)) {
                $portal->table($table)->where('permission_key', $from)->update(['permission_key' => $to]);
            }
        }

        $portal->table('access_permissions')->where('permission_key', $from)->delete();
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
