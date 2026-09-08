<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal logs and notifications. Schema also lives in sql/portal/schema.sql.
 * Fresh local installs get the tables from that dump; this creates them when
 * the portal database already exists (Bluehost) and they are still missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        if (! Schema::connection('portal')->hasTable('logs')) {
            Schema::connection('portal')->create('logs', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('employee_id');
                $table->string('action', 64);
                $table->string('resource', 191);
                $table->string('record_id', 191)->nullable();
                $table->text('message')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['employee_id', 'created_at'], 'idx_logs_employee_created');
                $table->index(['resource', 'created_at'], 'idx_logs_resource_created');
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('portal')->hasTable('notifications')) {
            Schema::connection('portal')->create('notifications', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('employee_id');
                $table->integer('actor_id')->nullable();
                $table->string('type', 64);
                $table->string('title', 255);
                $table->text('message');
                $table->string('link_path', 500)->nullable();
                $table->string('link_label', 100)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['employee_id', 'read_at', 'created_at'], 'idx_notifications_inbox');
                $table->index(['employee_id', 'created_at'], 'idx_notifications_employee_created');
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('actor_id')->references('id')->on('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('notifications');
        Schema::connection('portal')->dropIfExists('logs');
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
