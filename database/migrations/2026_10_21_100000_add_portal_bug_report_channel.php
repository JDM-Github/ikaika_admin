<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production and staging share one Jira project (2026-10-21, user), so a report records the server it was sent on
 * -- its API channel -- and each server follows only its own reports' issues. Null, for every report from before,
 * counts as production's.
 *
 * jira_create_started_at is stamped just before a server asks Jira to make the issue, and cleared once the new issue
 * is in step with its report: only then can a retry's search find an issue an unanswered create made, and until then
 * a read-back takes nothing from it. Reports already waiting to be sent get it, so their retry still searches first.
 * Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasColumn('bug_reports', 'channel')) {
            $schema->table('bug_reports', function (Blueprint $table): void {
                $table->string('channel', 32)->nullable()->after('reporter_id');
            });
        }
        if (! $schema->hasColumn('bug_reports', 'jira_create_started_at')) {
            $schema->table('bug_reports', function (Blueprint $table): void {
                $table->timestamp('jira_create_started_at')->nullable()->after('jira_synced_at');
            });
            DB::connection('portal')->table('bug_reports')
                ->whereNull('jira_issue_id')
                ->whereIn('jira_state', ['pending', 'failed'])
                ->update(['jira_create_started_at' => DB::raw('TIMESTAMP(reported_on)')]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        foreach (['channel', 'jira_create_started_at'] as $column) {
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
