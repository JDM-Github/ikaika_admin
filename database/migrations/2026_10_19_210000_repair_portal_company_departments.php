<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A local database left MIS and Engineering headless (2026-10-19, user): company_departments came out
 * MyISAM, where a test's rollback is never undone, and the access tests empty the heads as they set up.
 * Headless, Nathaniel and Steven lost the head presets -- Show employee information, Approve profile
 * changes under it, and the rest -- so Manage / Users lost its Profile changes tab and their own changes
 * waited. The table becomes InnoDB, and a head still empty is filled; a database that has them, or one
 * where a head was changed since, keeps what it has.
 */
return new class extends Migration
{
    private const HEADS = ['MIS' => '220202-0002', 'Engineering' => '220302-0003'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('company_departments')) {
            return;
        }
        $portal = DB::connection('portal');
        if ($portal->getDriverName() === 'mysql') {
            $engine = $portal->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['company_departments']);
            if ($engine !== null && strcasecmp((string) $engine->engine, 'InnoDB') !== 0) {
                $portal->statement('ALTER TABLE company_departments ENGINE = InnoDB');
            }
        }
        foreach (self::HEADS as $name => $idNo) {
            $id = $portal->table('employees')->where('id_no', $idNo)->value('id');
            if ($id !== null) {
                $portal->table('company_departments')->where('name', $name)->whereNull('head_employee_id')->update(['head_employee_id' => (int) $id]);
            }
        }
    }

    // Nothing to undo: InnoDB is what the table should have been, and the heads are the ones meant.
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
