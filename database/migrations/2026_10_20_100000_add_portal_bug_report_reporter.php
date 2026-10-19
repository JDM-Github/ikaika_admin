<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent an issue or request, when they say (2026-10-20, user: testers asked to be named). Sending
 * anonymously is now the sender's choice, so the column is null for every anonymous report and every
 * report from before. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')
            || Schema::connection('portal')->hasColumn('bug_reports', 'reporter_id')) {
            return;
        }
        Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
            // An employee on the core connection, so no foreign key across the two databases.
            $table->unsignedInteger('reporter_id')->nullable()->after('fixed_in');
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasColumn('bug_reports', 'reporter_id')) {
            return;
        }
        Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
            $table->dropColumn('reporter_id');
        });
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
