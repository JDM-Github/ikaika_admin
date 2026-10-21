<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's payment QR codes (2026-10-21, user): one per bank or e-wallet, kept on their Profile. Filing a
 * reimbursement picks one, and the claim keeps its own copy, so a QR changed later never rewrites a claim
 * already paid. Schema also lives in sql/portal/separate.sql.
 *
 * The engine is named, not left to the server, so the key to the employee is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || Schema::connection('portal')->hasTable('employee_payment_qrs')) {
            return;
        }

        Schema::connection('portal')->create('employee_payment_qrs', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->integer('id')->autoIncrement();
            $table->integer('employee_id');
            $table->string('bank_label', 120);
            $table->string('file_url', 1000);
            $table->string('file_name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->dateTime('date_updated');
            $table->unique(['employee_id', 'bank_label'], 'uq_employee_payment_qrs_bank');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('employee_payment_qrs');
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
