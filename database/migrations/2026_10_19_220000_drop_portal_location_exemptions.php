<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * location_exemptions lived a few hours (2026-10-19): once no save asks for a location and logs keep the
 * network's place only, nobody needed exempting, so the table goes where its create migration already ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->portalReachable()) {
            Schema::connection('portal')->dropIfExists('location_exemptions');
        }
    }

    public function down(): void {}

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
