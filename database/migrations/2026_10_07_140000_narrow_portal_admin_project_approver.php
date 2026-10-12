<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An admin's Project Approver defaults to their own department (plus the projects they lead), not every
 * project (2026-10-07, user). Executives and developers keep all. Mirrored in separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setAdminReach('department');
    }

    public function down(): void
    {
        $this->setAdminReach('all');
    }

    private function setAdminReach(string $reach): void
    {
        try {
            Schema::connection('portal')->getConnection()->getPdo();
        } catch (Throwable) {
            return;
        }
        if (! Schema::connection('portal')->hasTable('access_presets')) {
            return;
        }
        DB::connection('portal')->table('access_presets')
            ->where('role', 'admin')->where('permission_key', 'projects.manager')
            ->update(['reach' => $reach]);
    }
};
