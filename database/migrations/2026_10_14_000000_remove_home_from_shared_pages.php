<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home leaves the shareable pages (2026-10-14, user): a guest has no account, so the member's own
 * dashboard has nothing it could show them. Existing shares that granted it stop granting it.
 * Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'home';

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('shared_page_shareable')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('shared_page_shareable')->where('rule_key', self::KEY)->delete();
        if (Schema::connection('portal')->hasTable('shared_page_rules')) {
            $portal->table('shared_page_rules')->where('rule_key', self::KEY)->delete();
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('shared_page_shareable')) {
            return;
        }
        DB::connection('portal')->table('shared_page_shareable')->insertOrIgnore(['rule_key' => self::KEY, 'is_ready' => 1]);
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
