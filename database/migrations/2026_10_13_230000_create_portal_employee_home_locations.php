<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / Map's points (2026-10-13, user): each person's address on record turned into coordinates once,
 * with the text it came from, so only a changed address is looked up again (PortalHomeLocations). Schema
 * also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('employee_home_locations') || ! $schema->hasTable('employees')) {
            return;
        }
        $schema->create('employee_home_locations', function (Blueprint $table): void {
            $table->integer('employee_id')->primary();
            $table->string('query', 500);
            $table->string('source', 16);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('geocoded_at')->nullable();
            // The address on record's own point, kept apart from the home they set (2026-10-17).
            $table->string('suggested_query', 500)->nullable();
            $table->decimal('suggested_latitude', 10, 7)->nullable();
            $table->decimal('suggested_longitude', 10, 7)->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('employee_home_locations');
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
