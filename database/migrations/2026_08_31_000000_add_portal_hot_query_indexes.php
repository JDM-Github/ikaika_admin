<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date indexes for Submitted Reports and Calendar / Holidays.
 * Portal tables are not created by Laravel; this only adds indexes when the table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('portal', 'user_reports', 'report_date', 'idx_user_reports_date');
        $this->addIndexIfMissing('project_estimator', 'holidays', 'holiday_date', 'idx_pe_holidays_date');
    }

    public function down(): void
    {
        $this->dropIndexIfPresent('portal', 'user_reports', 'idx_user_reports_date');
        $this->dropIndexIfPresent('project_estimator', 'holidays', 'idx_pe_holidays_date');
    }

    private function addIndexIfMissing(string $connection, string $table, string $column, string $index): void
    {
        if (! $this->tableExists($connection, $table) || $this->indexExists($connection, $table, $index)) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($column, $index): void {
            $blueprint->index($column, $index);
        });
    }

    private function dropIndexIfPresent(string $connection, string $table, string $index): void
    {
        if (! $this->tableExists($connection, $table) || ! $this->indexExists($connection, $table, $index)) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    private function tableExists(string $connection, string $table): bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function indexExists(string $connection, string $table, string $index): bool
    {
        try {
            $db = Schema::connection($connection)->getConnection();
            $row = $db->selectOne(
                'select 1 as present from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                [$db->getDatabaseName(), $table, $index],
            );

            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }
};
