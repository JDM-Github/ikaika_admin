<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Transactions' payor list (2026-10-21, user): a payor or payee typed into Add Transaction is
 * kept on the list at once, and one can be taken off it. The list is every name the ledger already
 * carries plus the rows here, less any row here marked hidden; a hidden name stays off the list even
 * while old transactions still carry it. Schema also lives in sql/portal/separate.sql.
 *
 * The engine is named, not left to the server, so the key to whoever changed a row is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || Schema::connection('portal')->hasTable('finance_payors')) {
            return;
        }

        Schema::connection('portal')->create('finance_payors', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->integer('id')->autoIncrement();
            $table->string('name', 191)->unique('uq_finance_payors_name');
            $table->boolean('is_hidden')->default(false);
            $table->integer('updated_by')->nullable();
            $table->dateTime('date_updated');
            $table->foreign('updated_by')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('finance_payors');
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
