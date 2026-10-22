<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a linked report's assignees, dates, parent and team were first kept in step with its Jira issue (2026-10-22,
 * user). The sync is off until MIS turns it on; until a report is stamped, its first read-back merges what each side
 * has rather than taking Jira's, so turning it on changes nothing either side already holds. Mirrored in
 * sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (! Schema::connection('portal')->hasColumn('bug_reports', 'jira_fields_synced_at')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->timestamp('jira_fields_synced_at')->nullable()->after('jira_assignee_name');
            });
        }
    }

    public function down(): void
    {
        if ($this->portalReachable() && Schema::connection('portal')->hasColumn('bug_reports', 'jira_fields_synced_at')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->dropColumn('jira_fields_synced_at');
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
