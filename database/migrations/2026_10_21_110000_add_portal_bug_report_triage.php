<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Triage (2026-10-21, user): Won't fix is shown as Cancelled, and a cancelled report says why -- nothing will change
 * (wont_fix), or it duplicates another report (duplicate), which duplicate_of names. The status value stays
 * wont_fix, which the apps from before read strictly. Every report already cancelled was cancelled as won't fix.
 *
 * No foreign key on duplicate_of: an original archived or deleted later must not take its duplicates' triage with
 * it, and a report that names one no longer there says so. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasColumn('bug_reports', 'cancel_reason')) {
            $schema->table('bug_reports', function (Blueprint $table): void {
                $table->string('cancel_reason', 20)->nullable()->after('status');
            });
            DB::connection('portal')->table('bug_reports')->where('status', 'wont_fix')->update(['cancel_reason' => 'wont_fix']);
        }
        if (! $schema->hasColumn('bug_reports', 'duplicate_of')) {
            $schema->table('bug_reports', function (Blueprint $table): void {
                $table->unsignedInteger('duplicate_of')->nullable()->after('cancel_reason');
                $table->index('duplicate_of');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasColumn('bug_reports', 'duplicate_of')) {
            $schema->table('bug_reports', function (Blueprint $table): void {
                $table->dropIndex(['duplicate_of']);
                $table->dropColumn('duplicate_of');
            });
        }
        if ($schema->hasColumn('bug_reports', 'cancel_reason')) {
            $schema->table('bug_reports', function (Blueprint $table): void {
                $table->dropColumn('cancel_reason');
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
