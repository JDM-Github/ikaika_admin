<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The portal's mirror of the Calendar base's Holidays table (2026-10-22, user). portal:sync-calendar
 * fills it; PortalHolidays reads it instead of the Project Estimator database, so the calendar and
 * the report/overtime holiday checks survive Airtable being unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('calendar_holidays')) {
            $schema->create('calendar_holidays', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('airtable_record_id', 20)->nullable()->unique();
                $table->date('holiday_date');
                $table->string('name', 255)->nullable();
                $table->string('type', 20)->default('special');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index('holiday_date', 'calendar_holidays_date');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        Schema::connection('portal')->dropIfExists('calendar_holidays');
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
