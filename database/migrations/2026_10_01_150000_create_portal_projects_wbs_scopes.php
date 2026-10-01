<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WBS codes chosen for a project in Manage Scopes (codes from project_scope_t1..t4 wbs_code). Schema
 * also lives in sql/portal/separate.sql; this adds the table where the portal database already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('projects_wbs_scopes') || ! $schema->hasTable('projects')) {
            return;
        }
        $schema->create('projects_wbs_scopes', function (Blueprint $table): void {
            $table->integer('project_id');
            $table->string('wbs_code', 32);
            $table->primary(['project_id', 'wbs_code']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('projects_wbs_scopes');
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
