<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edit transactions changes a transaction's amount too (2026-10-13, user), so the power says so.
 * Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'finance.transactions.edit';

    private const DESCRIPTION = 'Change a transaction\'s name, amount, date, payor or payee, notes and receipt. Its type, envelope and account stay as they were.';

    private const PREVIOUS = 'Change a transaction\'s name, date, payor or payee, notes and receipt. Its amount, type, envelope and account stay as they were.';

    public function up(): void
    {
        $this->describe(self::DESCRIPTION);
    }

    public function down(): void
    {
        $this->describe(self::PREVIOUS);
    }

    private function describe(string $description): void
    {
        try {
            Schema::connection('portal')->getConnection()->getPdo();
        } catch (Throwable) {
            return;
        }
        if (! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        DB::connection('portal')->table('access_permissions')->where('permission_key', self::KEY)->update(['description' => $description]);
    }
};
