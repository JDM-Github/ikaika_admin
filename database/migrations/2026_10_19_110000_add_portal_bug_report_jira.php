<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issues & Requests in Jira (2026-10-19, user): each report becomes a Jira issue -- on its own as it comes
 * in, with an off switch, or sent by hand -- and Jira's progress comes back by webhook and the scheduler.
 * A report keeps the issue it became and where sending it got to:
 *
 * - jira_issue_id: Jira's own id, stable when an issue moves project, which deliveries and syncs look up.
 * - jira_issue_key, jira_status: the key and status name as Jira last said them.
 * - jira_state: null never sent, pending, sent, failed, or deleted once Jira no longer has it.
 * - jira_error: Jira's status code and words for the last failure, never a credential.
 * - jira_attached: how many screenshots are already in Jira, so a retry carries on from there.
 * - jira_attempts: automatic tries so far; the scheduler gives up after five.
 * - jira_synced_at: the last time Jira was asked about it.
 *
 * None of these can say who sent a report. Sending it, trying again and the switch are a power of their
 * own under Issues & Requests, held by the same people: every MIS member and admin, and developers.
 * Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'bug-reports.jira';

    private const ROLES = ['member', 'admin', 'developer'];

    private const COLUMNS = ['jira_issue_id', 'jira_issue_key', 'jira_state', 'jira_status', 'jira_error', 'jira_attached', 'jira_attempts', 'jira_synced_at'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (! Schema::connection('portal')->hasColumn('bug_reports', 'jira_issue_id')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->string('jira_issue_id', 20)->nullable()->unique();
                $table->string('jira_issue_key', 32)->nullable();
                $table->string('jira_state', 16)->nullable();
                $table->string('jira_status', 100)->nullable();
                $table->string('jira_error', 255)->nullable();
                $table->unsignedTinyInteger('jira_attached')->default(0);
                $table->unsignedTinyInteger('jira_attempts')->default(0);
                $table->timestamp('jira_synced_at')->nullable();
            });
        }

        if (! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'bug-reports',
            'label' => 'Send to Jira',
            'description' => 'Send an issue or request to Jira by hand, try a failed one again, and turn sending new ones to Jira on or off.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'only_department' => 'MIS',
            'sort_order' => 991,
        ]);
        foreach (self::ROLES as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => self::KEY,
                'reach' => null,
                'include_executives' => 0,
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (Schema::connection('portal')->hasColumn('bug_reports', 'jira_issue_id')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->dropUnique(['jira_issue_id']);
                $table->dropColumn(self::COLUMNS);
            });
        }
        if (Schema::connection('portal')->hasTable('access_permissions')) {
            DB::connection('portal')->table('access_presets')->where('permission_key', self::KEY)->delete();
            DB::connection('portal')->table('access_permissions')->where('permission_key', self::KEY)->delete();
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
