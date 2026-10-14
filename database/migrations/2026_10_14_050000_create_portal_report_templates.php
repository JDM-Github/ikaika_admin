<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report templates move off the browser's own storage and into the database, per user (2026-10-14,
 * user): each person's saved recipes, readable only by their owner. Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || Schema::connection('portal')->hasTable('report_templates')) {
            return;
        }
        Schema::connection('portal')->create('report_templates', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('employee_id');
            $table->string('name', 100);
            $table->text('notes')->nullable();
            $table->json('body');
            $table->dateTime('date_created')->useCurrent();
            $table->dateTime('date_updated')->nullable();
            $table->index(['employee_id', 'name'], 'idx_report_templates_employee');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        Schema::connection('portal')->dropIfExists('report_templates');
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
