<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who handles a bug report and what its fix is (2026-10-13, user): the MIS member assigned to it, the
 * semantic version level the fix needs (major, minor or patch), and the version it shipped in. The
 * assignee is MIS's own, never the reporter, who stays unknown. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
            if (! Schema::connection('portal')->hasColumn('bug_reports', 'assignee_id')) {
                // An employee on the core connection, so no foreign key across the two databases.
                $table->unsignedInteger('assignee_id')->nullable()->after('note');
            }
            if (! Schema::connection('portal')->hasColumn('bug_reports', 'version_level')) {
                $table->string('version_level', 10)->nullable()->after('assignee_id');
            }
            if (! Schema::connection('portal')->hasColumn('bug_reports', 'fixed_in')) {
                $table->string('fixed_in', 20)->nullable()->after('version_level');
            }
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
            $table->dropColumn(['assignee_id', 'version_level', 'fixed_in']);
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
