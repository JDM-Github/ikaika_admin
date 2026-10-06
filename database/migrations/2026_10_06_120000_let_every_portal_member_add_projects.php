<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anyone may add a project, whatever their role (2026-10-06). Admins, executives and developers
 * already hold Add projects at all; members now do too. A member's project still waits as Pending
 * until a Project Manager who covers its department approves it, and a manager can still limit the
 * power for one person. The same row lives in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_presets')) {
            return;
        }

        DB::connection('portal')->table('access_presets')->updateOrInsert(
            ['role' => 'member', 'permission_key' => 'projects.create'],
            ['reach' => 'all', 'include_executives' => 0],
        );
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_presets')) {
            return;
        }

        DB::connection('portal')->table('access_presets')
            ->where('role', 'member')
            ->where('permission_key', 'projects.create')
            ->delete();
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
