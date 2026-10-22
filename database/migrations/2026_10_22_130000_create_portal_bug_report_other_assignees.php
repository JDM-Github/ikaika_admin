<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everyone but the first who works on a report, in order (2026-10-22, user: an issue can have more than one
 * assignee). The first stays bug_reports.assignee_id -- the one Jira's single assignee is, read back and older apps
 * read -- so nothing written before today needs moving; these are the issue's watchers in Jira. Mirrored in
 * sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')
            || Schema::connection('portal')->hasTable('bug_report_other_assignees')) {
            return;
        }
        Schema::connection('portal')->create('bug_report_other_assignees', function (Blueprint $table): void {
            $table->unsignedInteger('bug_report_id');
            $table->unsignedInteger('employee_id');
            $table->unsignedSmallInteger('position');
            $table->primary(['bug_report_id', 'employee_id']);
            $table->index('employee_id');
            $table->foreign('bug_report_id')->references('id')->on('bug_reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if ($this->portalReachable()) {
            Schema::connection('portal')->dropIfExists('bug_report_other_assignees');
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
