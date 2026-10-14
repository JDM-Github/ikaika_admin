<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's own pages leave the shareable pages (2026-10-14, user): reports, requests and the
 * User section are the signed-in person's own work, and a guest has no account to file or track it
 * with -- a grant could only open an empty page. Removed: projects.created and every reports.*,
 * requests.*, user.* key. Existing shares that granted any stop granting it. Also in separate.sql.
 */
return new class extends Migration
{
    /**
     * @var array<string, int>
     */
    private const REMOVED = [
        'projects.created' => 1,
        'reports.daily' => 0,
        'reports.late' => 0,
        'reports.submitted' => 1,
        'reports.submitted.edit' => 0,
        'requests.own' => 1,
        'requests.own.edit' => 0,
        'requests.leave' => 0,
        'requests.overtime' => 0,
        'requests.offset' => 0,
        'requests.reimbursement' => 0,
        'user.logs' => 1,
        'user.recycle' => 1,
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
