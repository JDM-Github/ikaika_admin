<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activities a project takes out of its Type of project's allowed list (2026-10-13, user): a project
 * may only remove, never add, so a project with no rows here has its type's whole list. Schema also lives
 * in sql/portal/separate.sql; this adds the table where the portal database already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('projects_removed_activities') || ! $schema->hasTable('projects')) {
            return;
        }
        $schema->create('projects_removed_activities', function (Blueprint $table): void {
            $table->integer('project_id');
            $table->string('activity_code', 32);
            $table->primary(['project_id', 'activity_code']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('projects_removed_activities');
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
