<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitbucket code activity for MIS projects (view-projects-dashboard-plan.md, step 7): the linked
 * repositories with their sync state, which projects each serves, and their commits. Schema also lives
 * in sql/portal/separate.sql; this adds the tables where the portal database already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('projects') || ! $schema->hasTable('employees')) {
            return;
        }
        if (! $schema->hasTable('bitbucket_repositories')) {
            $schema->create('bitbucket_repositories', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('workspace', 64);
                $table->string('repo_slug', 128);
                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_sync_error', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['workspace', 'repo_slug'], 'uq_bitbucket_repositories');
            });
        }
        if (! $schema->hasTable('projects_bitbucket_repositories')) {
            $schema->create('projects_bitbucket_repositories', function (Blueprint $table): void {
                $table->integer('project_id');
                $table->integer('repository_id');
                $table->primary(['project_id', 'repository_id']);
                $table->foreign('project_id', 'fk_projects_bitbucket_repositories_project')
                    ->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('repository_id', 'fk_projects_bitbucket_repositories_repository')
                    ->references('id')->on('bitbucket_repositories')->cascadeOnDelete();
            });
        }
        if (! $schema->hasTable('bitbucket_commits')) {
            $schema->create('bitbucket_commits', function (Blueprint $table): void {
                $table->integer('repository_id');
                $table->char('hash', 40);
                $table->string('author_name', 255)->nullable();
                $table->string('author_email', 255)->nullable();
                $table->integer('employee_id')->nullable();
                $table->dateTime('committed_at');
                $table->text('message')->nullable();
                $table->unsignedInteger('lines_added')->default(0);
                $table->unsignedInteger('lines_removed')->default(0);
                $table->unsignedInteger('files_changed')->default(0);
                $table->boolean('is_merge')->default(false);
                $table->primary(['repository_id', 'hash']);
                $table->index(['repository_id', 'committed_at'], 'ix_bitbucket_commits_repository_day');
                $table->index(['employee_id', 'committed_at'], 'ix_bitbucket_commits_employee_day');
                $table->foreign('repository_id', 'fk_bitbucket_commits_repository')
                    ->references('id')->on('bitbucket_repositories')->cascadeOnDelete();
                $table->foreign('employee_id', 'fk_bitbucket_commits_employee')
                    ->references('id')->on('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        $schema->dropIfExists('bitbucket_commits');
        $schema->dropIfExists('projects_bitbucket_repositories');
        $schema->dropIfExists('bitbucket_repositories');
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
