<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Reports and Flag users (2026-10-20, user): Flag Reports takes the name of its own tab, Daily
 * Reports, and flagging someone becomes a power of its own under it instead of following Approve or
 * reject. Whoever holds, was granted, or had switched off Approve or reject gets the same for Flag
 * users, so nothing changes until a head admin switches it. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const PAGE = 'manage.requests.flagged';

    private const KEY = 'manage.requests.flagged.flag';

    private const FROM = 'manage.requests.flagged.decide';

    private const HOLDINGS = ['access_presets', 'access_grants', 'access_personal', 'access_limits', 'access_toggles'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', self::FROM)->exists()) {
            return;
        }

        $portal->table('access_permissions')->where('permission_key', self::PAGE)->update([
            'label' => 'Daily Reports',
            'description' => 'See the daily reports filed while their author was flagged.',
        ]);
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => self::PAGE,
            'label' => 'Flag users',
            'description' => 'Put someone in {UserName|Your} reach on report review, or take them off it. Every daily report they file then waits here.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => null,
            // Ties sort by key, so it follows Approve or reject.
            'sort_order' => 821,
        ]);
        foreach (self::HOLDINGS as $table) {
            if (! Schema::connection('portal')->hasTable($table)) {
                continue;
            }
            foreach ($portal->table($table)->where('permission_key', self::FROM)->get() as $row) {
                $copy = array_merge((array) $row, ['permission_key' => self::KEY]);
                unset($copy['id']);
                $portal->table($table)->insertOrIgnore($copy);
            }
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        foreach ([...self::HOLDINGS, 'access_self_reach', 'access_permissions'] as $table) {
            if (Schema::connection('portal')->hasTable($table)) {
                $portal->table($table)->where('permission_key', self::KEY)->delete();
            }
        }
        $portal->table('access_permissions')->where('permission_key', self::PAGE)->update([
            'label' => 'Flag Reports',
            'description' => 'See reports filed while their author was on a warning.',
        ]);
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
