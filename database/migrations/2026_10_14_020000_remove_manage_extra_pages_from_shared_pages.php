<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three more pages leave the shareable list (2026-10-14, user): Project Approver (a decided queue a
 * guest cannot act on), Backup (an administrator's restore point) and Templates and footers (the
 * signature library behind Send Email). Existing shares that granted any stop granting it.
 * Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * @var array<string, int>
     */
    private const REMOVED = [
        'projects.manager' => 1,
        'administration.backup' => 0,
        'email.library' => 1,
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('shared_page_shareable')) {
            return;
        }
        $portal = DB::connection('portal');
        $keys = array_keys(self::REMOVED);
        $portal->table('shared_page_shareable')->whereIn('rule_key', $keys)->delete();
        if (Schema::connection('portal')->hasTable('shared_page_rules')) {
            $portal->table('shared_page_rules')->whereIn('rule_key', $keys)->delete();
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('shared_page_shareable')) {
            return;
        }
        foreach (self::REMOVED as $key => $isReady) {
            DB::connection('portal')->table('shared_page_shareable')->insertOrIgnore(['rule_key' => $key, 'is_ready' => $isReady]);
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
