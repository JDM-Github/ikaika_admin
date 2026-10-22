<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClashFlow's real workflow records (P2): individual groups and batches with optimistic versions,
 * one project record for the run/levels/package, and an audit row for every write.
 * The old clashflow_states table remains only for the shared playbook library.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('clashflow_records')) {
            $schema->create('clashflow_records', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('clashflow_project_id');
                $table->string('record_type', 20);
                $table->string('record_key', 100);
                $table->longText('payload');
                $table->unsignedInteger('version')->default(1);
                $table->integer('updated_by')->nullable();
                $table->dateTime('updated_at');
                $table->unique(['clashflow_project_id', 'record_type', 'record_key'], 'clashflow_records_unique');
                $table->foreign('clashflow_project_id')->references('id')->on('clashflow_projects')->cascadeOnDelete();
                $table->foreign('updated_by')->references('id')->on('employees')->nullOnDelete();
            });
        }
        if (! $schema->hasTable('clashflow_audit')) {
            $schema->create('clashflow_audit', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('clashflow_project_id');
                $table->integer('actor_employee_id')->nullable();
                $table->string('action', 20);
                $table->string('record_type', 20);
                $table->string('record_key', 100);
                $table->unsignedInteger('old_version')->default(0);
                $table->unsignedInteger('new_version');
                $table->longText('before_payload')->nullable();
                $table->longText('after_payload')->nullable();
                $table->string('text', 255);
                $table->dateTime('created_at');
                $table->foreign('clashflow_project_id')->references('id')->on('clashflow_projects')->cascadeOnDelete();
                $table->foreign('actor_employee_id')->references('id')->on('employees')->nullOnDelete();
                $table->index(['clashflow_project_id', 'created_at'], 'clashflow_audit_project_created');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        Schema::connection('portal')->dropIfExists('clashflow_audit');
        Schema::connection('portal')->dropIfExists('clashflow_records');
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
