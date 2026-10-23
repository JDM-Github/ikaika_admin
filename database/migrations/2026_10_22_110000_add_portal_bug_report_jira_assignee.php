<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who works on a report is its Jira issue's assignee too (2026-10-22, user). jira_assignee_id is the issue's
 * assignee the last time the two sides agreed: a read-back that finds the same one changes nothing -- which keeps a
 * member Jira has no account for assigned here -- and one that finds another takes it. jira_assignee_name is that
 * person's name only while they are not in MIS, so the page can say who has it in Jira. Mirrored in
 * sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (! Schema::connection('portal')->hasColumn('bug_reports', 'jira_assignee_id')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->string('jira_assignee_id', 128)->nullable()->after('jira_create_started_at');
                $table->string('jira_assignee_name', 255)->nullable()->after('jira_assignee_id');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        foreach (['jira_assignee_name', 'jira_assignee_id'] as $column) {
            if (Schema::connection('portal')->hasColumn('bug_reports', $column)) {
                Schema::connection('portal')->table('bug_reports', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
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
