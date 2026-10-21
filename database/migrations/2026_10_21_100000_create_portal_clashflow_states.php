<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClashFlow's mock workflow state moves from a MySQL on each laptop to the admin (2026-10-21, user):
 * ClashFlow talks to the admin through the API only. One row per project ("project-<id>") plus the
 * shared playbook library ("library"). Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || Schema::connection('portal')->hasTable('clashflow_states')) {
            return;
        }
        Schema::connection('portal')->create('clashflow_states', function (Blueprint $table): void {
            $table->string('state_key', 64)->primary();
            $table->integer('project_id')->nullable();
            $table->longText('state');
            $table->integer('updated_by')->nullable();
            $table->dateTime('updated_at');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        Schema::connection('portal')->dropIfExists('clashflow_states');
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
