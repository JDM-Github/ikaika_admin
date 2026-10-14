<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / Onboarding and Manage / Map join the shareable pages (2026-10-14, user), both under
 * Manage as the sidebar files them. Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * @var array<string, int>
     */
    private const ADDED = [
        'manage.onboarding' => 1,
        'manage.map' => 1,
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('shared_page_shareable')) {
            return;
        }
        foreach (self::ADDED as $key => $isReady) {
            DB::connection('portal')->table('shared_page_shareable')->insertOrIgnore(['rule_key' => $key, 'is_ready' => $isReady]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('shared_page_shareable')) {
            return;
        }
        DB::connection('portal')->table('shared_page_shareable')->whereIn('rule_key', array_keys(self::ADDED))->delete();
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
