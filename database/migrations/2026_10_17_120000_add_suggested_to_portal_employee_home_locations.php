<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employee_home_locations kept one point, so setting a home overwrote the address on record's own
 * point and "Use default" then returned to the home the person set, not their data (2026-10-17,
 * user). The address point now lives in its own suggested_* columns, which setOwn leaves alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('employee_home_locations') || $schema->hasColumn('employee_home_locations', 'suggested_query')) {
            return;
        }

        $schema->table('employee_home_locations', function (Blueprint $table): void {
            $table->string('suggested_query', 500)->nullable()->after('geocoded_at');
            $table->decimal('suggested_latitude', 10, 7)->nullable()->after('suggested_query');
            $table->decimal('suggested_longitude', 10, 7)->nullable()->after('suggested_latitude');
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasColumn('employee_home_locations', 'suggested_query')) {
            return;
        }

        Schema::connection('portal')->table('employee_home_locations', function (Blueprint $table): void {
            $table->dropColumn(['suggested_query', 'suggested_latitude', 'suggested_longitude']);
        });
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
