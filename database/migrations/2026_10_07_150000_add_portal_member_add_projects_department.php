<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A member adds projects for their own department only (2026-10-07), rather than for any: Add New
 * Project then offers just that one department, and the server refuses another. Admins, executives
 * and developers keep Add projects at all, and a manager can still widen it for one person. The same
 * row lives in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setMemberReach('department');
    }

    public function down(): void
    {
        $this->setMemberReach('all');
    }

    private function setMemberReach(string $reach): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_presets')) {
            return;
        }

        DB::connection('portal')->table('access_presets')->updateOrInsert(
            ['role' => 'member', 'permission_key' => 'projects.create'],
            ['reach' => $reach, 'include_executives' => 0],
        );
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
