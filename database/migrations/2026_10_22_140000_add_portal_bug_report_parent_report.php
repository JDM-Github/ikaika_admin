<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The report a report sits under (2026-10-22, user: epics are reports too, and a report can sit under another): an
 * epic, whose child it is, or another report, whose sub-task it is -- as Jira's levels go, epic, issue, sub-task.
 * No foreign key: a parent in the Recycle Bin leaves its children where they are. parent_key stays for an epic Jira
 * alone has. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (! Schema::connection('portal')->hasColumn('bug_reports', 'parent_report_id')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->unsignedInteger('parent_report_id')->nullable()->after('parent_summary');
                $table->index('parent_report_id');
            });
        }
    }

    public function down(): void
    {
        if ($this->portalReachable() && Schema::connection('portal')->hasColumn('bug_reports', 'parent_report_id')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->dropIndex(['parent_report_id']);
                $table->dropColumn('parent_report_id');
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
