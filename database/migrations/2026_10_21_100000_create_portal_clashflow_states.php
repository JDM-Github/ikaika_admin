<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClashFlow, the desktop clash app, talks to the admin through the API only (2026-10-21, user), so its
 * data lives here. clashflow_projects: what someone created in ClashFlow, either a team project linked
 * to one portal project (roles follow that project) or a solo project bound to its creator's account.
 * clashflow_states: each ClashFlow project's mock workflow ("project-<clashflow_projects.id>") plus the
 * shared playbook library ("library"). Both also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('clashflow_projects')) {
            $schema->create('clashflow_projects', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('mode', 10);
                // Team only: one ClashFlow project per portal project.
                $table->integer('portal_project_id')->nullable()->unique();
                // Solo only; a team project shows its portal project's name and number.
                $table->string('name', 255)->nullable();
                $table->string('number', 100)->nullable();
                $table->integer('owner_id');
                $table->dateTime('created_at');
                $table->foreign('portal_project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('owner_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
        if (! $schema->hasTable('clashflow_states')) {
            $schema->create('clashflow_states', function (Blueprint $table): void {
                $table->string('state_key', 64)->primary();
                $table->unsignedInteger('clashflow_project_id')->nullable();
                $table->longText('state');
                $table->integer('updated_by')->nullable();
                $table->dateTime('updated_at');
                $table->foreign('clashflow_project_id')->references('id')->on('clashflow_projects')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        Schema::connection('portal')->dropIfExists('clashflow_states');
        Schema::connection('portal')->dropIfExists('clashflow_projects');
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
