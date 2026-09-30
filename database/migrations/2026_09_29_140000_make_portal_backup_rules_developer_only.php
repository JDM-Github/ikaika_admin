<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * All Backups is parked until Airtable is retired (docs/power/backups-plan.md), so its rules become
 * developer-only: held solely in Developer mode, and no gift or limit can reach them. The same
 * UPDATE lives in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        DB::connection('portal')->table('access_permissions')
            ->where('permission_key', 'like', 'administration.backup%')
            ->update(['min_tier' => 'developer']);
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        DB::connection('portal')->table('access_permissions')
            ->where('permission_key', 'like', 'administration.backup%')
            ->update(['min_tier' => null]);
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
