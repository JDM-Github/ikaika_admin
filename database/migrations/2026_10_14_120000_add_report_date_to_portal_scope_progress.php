<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage Scopes (2026-10-14, user): a scope's current progress is the one from the newest report day, so a
 * late report for an older day no longer overrides it. The day sits on the progress row itself, so the report
 * picker reads it without touching user_reports. Schema also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('projects_scope_progress') || $schema->hasColumn('projects_scope_progress', 'report_date')) {
            return;
        }
        $schema->table('projects_scope_progress', function (Blueprint $table): void {
            $table->date('report_date')->nullable()->after('user_report_id');
            $table->index(['project_id', 'wbs_code', 'report_date', 'id'], 'projects_scope_progress_day');
        });
        if ($schema->hasTable('user_reports')) {
            DB::connection('portal')->statement(
                'UPDATE projects_scope_progress progress JOIN user_reports report ON report.id = progress.user_report_id SET progress.report_date = DATE(report.report_date)',
            );
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('projects_scope_progress') && $schema->hasColumn('projects_scope_progress', 'report_date')) {
            $schema->table('projects_scope_progress', function (Blueprint $table): void {
                $table->dropIndex('projects_scope_progress_day');
                $table->dropColumn('report_date');
            });
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
