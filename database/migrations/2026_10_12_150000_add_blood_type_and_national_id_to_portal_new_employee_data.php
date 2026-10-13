<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Registration asks for a blood type and, optionally, the national ID (PhilSys) number
 * (2026-10-12, user), so New Employee Data keeps both. Portal-only: Airtable's table has neither, and
 * the sync never sends them. Schema also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('new_employee_data')) {
            return;
        }
        if (! $schema->hasColumn('new_employee_data', 'blood_type')) {
            $schema->table('new_employee_data', function (Blueprint $table): void {
                $table->string('blood_type', 3)->nullable()->after('date_of_birth');
            });
        }
        if (! $schema->hasColumn('new_employee_data', 'national_id_no')) {
            $schema->table('new_employee_data', function (Blueprint $table): void {
                $table->string('national_id_no', 30)->nullable()->after('hdmf_no');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        foreach (['blood_type', 'national_id_no'] as $column) {
            if ($schema->hasColumn('new_employee_data', $column)) {
                $schema->table('new_employee_data', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
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
