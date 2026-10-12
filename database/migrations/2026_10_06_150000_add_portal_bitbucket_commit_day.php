<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each commit's day in company time (committed_on), what a Daily or Late Report for that date reads; when
 * its line counts were fetched (counted_at); and whether a repository's listing ever reached its first
 * commit (history_complete). Schema also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('bitbucket_repositories') && ! $schema->hasColumn('bitbucket_repositories', 'history_complete')) {
            $schema->table('bitbucket_repositories', function (Blueprint $table): void {
                $table->boolean('history_complete')->default(false)->after('last_sync_error');
            });
        }
        if ($schema->hasTable('bitbucket_commits') && ! $schema->hasColumn('bitbucket_commits', 'committed_on')) {
            $schema->table('bitbucket_commits', function (Blueprint $table): void {
                $table->date('committed_on')->after('committed_at');
                $table->timestamp('counted_at')->nullable()->after('is_merge');
                // The new index first: the employee foreign key needs one to lean on while the old one goes.
                $table->index(['employee_id', 'committed_on'], 'ix_bitbucket_commits_employee_on');
            });
            $schema->table('bitbucket_commits', function (Blueprint $table): void {
                $table->dropIndex('ix_bitbucket_commits_employee_day');
            });
            $schema->table('bitbucket_commits', function (Blueprint $table): void {
                $table->renameIndex('ix_bitbucket_commits_employee_on', 'ix_bitbucket_commits_employee_day');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasColumn('bitbucket_commits', 'committed_on')) {
            $schema->table('bitbucket_commits', function (Blueprint $table): void {
                $table->index(['employee_id', 'committed_at'], 'ix_bitbucket_commits_employee_at');
            });
            $schema->table('bitbucket_commits', function (Blueprint $table): void {
                $table->dropIndex('ix_bitbucket_commits_employee_day');
                $table->dropColumn(['committed_on', 'counted_at']);
            });
            $schema->table('bitbucket_commits', function (Blueprint $table): void {
                $table->renameIndex('ix_bitbucket_commits_employee_at', 'ix_bitbucket_commits_employee_day');
            });
        }
        if ($schema->hasColumn('bitbucket_repositories', 'history_complete')) {
            $schema->table('bitbucket_repositories', function (Blueprint $table): void {
                $table->dropColumn('history_complete');
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
