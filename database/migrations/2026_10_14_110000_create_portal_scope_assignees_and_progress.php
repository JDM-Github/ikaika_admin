<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manage Scopes (2026-10-13, user): members assigned to a project's scopes (any level, several each, only
 * the project's own people), and the progress (0-100%) they report on them through their reports; the
 * latest entry is the scope's current progress. Schema also lives in sql/portal/separate.sql.
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
        if (! $schema->hasTable('projects_scope_assignees')) {
            $schema->create('projects_scope_assignees', function (Blueprint $table): void {
                $table->integer('project_id');
                $table->string('wbs_code', 32);
                $table->integer('employee_id');
                $table->primary(['project_id', 'wbs_code', 'employee_id']);
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
        if (! $schema->hasTable('projects_scope_progress')) {
            $schema->create('projects_scope_progress', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->integer('project_id');
                $table->string('wbs_code', 32);
                $table->integer('employee_id');
                $table->integer('user_report_id')->nullable();
                $table->unsignedTinyInteger('percent');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['project_id', 'wbs_code', 'id'], 'projects_scope_progress_latest');
                $table->index('user_report_id', 'projects_scope_progress_report');
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        Schema::connection('portal')->dropIfExists('projects_scope_progress');
        Schema::connection('portal')->dropIfExists('projects_scope_assignees');
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
