<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar events members create from Event Calendar. Schema also lives in sql/portal/schema.sql.
 * Fresh local installs get the tables from that dump; this creates them when the portal
 * database already exists and they are still missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        if (! Schema::connection('portal')->hasTable('calendar_events')) {
            Schema::connection('portal')->create('calendar_events', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('title', 255);
                $table->text('details')->nullable();
                $table->date('starts_on');
                $table->time('starts_at')->nullable();
                $table->date('ends_on')->nullable();
                $table->time('ends_at')->nullable();
                $table->string('category', 32);
                $table->string('audience', 32);
                $table->integer('created_by');
                $table->dateTime('date_created')->nullable();
                $table->index('starts_on', 'idx_calendar_events_starts_on');
                $table->index('created_by', 'idx_calendar_events_created_by');
                $table->foreign('created_by')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('portal')->hasTable('calendar_event_departments')) {
            Schema::connection('portal')->create('calendar_event_departments', function (Blueprint $table): void {
                $table->integer('event_id');
                $table->string('department', 100);
                $table->primary(['event_id', 'department']);
                $table->foreign('event_id')->references('id')->on('calendar_events')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('portal')->hasTable('calendar_event_members')) {
            Schema::connection('portal')->create('calendar_event_members', function (Blueprint $table): void {
                $table->integer('event_id');
                $table->integer('employee_id');
                $table->primary(['event_id', 'employee_id']);
                $table->index('employee_id', 'idx_calendar_event_members_employee');
                $table->foreign('event_id')->references('id')->on('calendar_events')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('calendar_event_members');
        Schema::connection('portal')->dropIfExists('calendar_event_departments');
        Schema::connection('portal')->dropIfExists('calendar_events');
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
