<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missing Reports' reminder marks (2026-10-21, user): who was emailed a reminder for which day, when,
 * and by whom -- nobody for the scheduled run, which sends as the portal. Every reminder path writes
 * them through PortalEmailMessages, and Missing Reports reads them so nobody is reminded twice. Schema
 * also lives in sql/portal/separate.sql; this creates the table where the portal database already exists.
 *
 * The engine is named, not left to the server: the keys tie a mark to its person, its email and its
 * sender, and a server that defaults to MyISAM would quietly drop all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || Schema::connection('portal')->hasTable('report_reminders')) {
            return;
        }

        Schema::connection('portal')->create('report_reminders', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->integer('id')->autoIncrement();
            $table->integer('employee_id');
            $table->date('report_date');
            $table->integer('message_id')->nullable();
            $table->integer('sent_by')->nullable();
            $table->dateTime('date_created');
            $table->index(['report_date', 'employee_id'], 'idx_report_reminders_day');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('email_messages')->nullOnDelete();
            $table->foreign('sent_by')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('report_reminders');
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
