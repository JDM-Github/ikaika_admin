<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * location_exemptions (sql/portal/separate.sql): people whose actions are never located. Seeds Steven
 * Gaurano, who asked for it (2026-10-19), and drops any home pin he already had so he leaves Manage / Map.
 */
return new class extends Migration
{
    private const STEVEN_ID_NO = '220302-0003';

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('employees')) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('location_exemptions')) {
            $schema->create('location_exemptions', function (Blueprint $table): void {
                $table->integer('employee_id')->primary();
                $table->string('reason', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        $portal = DB::connection('portal');
        $steven = $portal->table('employees')->where('id_no', self::STEVEN_ID_NO)->value('id');
        if ($steven === null) {
            return;
        }
        $portal->table('location_exemptions')->insertOrIgnore(['employee_id' => (int) $steven, 'reason' => 'Asked that his location is never recorded']);
        if ($schema->hasTable('employee_home_locations')) {
            $portal->table('employee_home_locations')->where('employee_id', (int) $steven)->delete();
        }
    }

    public function down(): void
    {
        if ($this->portalReachable()) {
            Schema::connection('portal')->dropIfExists('location_exemptions');
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
