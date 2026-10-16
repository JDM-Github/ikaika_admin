<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * leave_types: the company's leave vocabulary, editable on Manage / References instead of a
 * hardcoded list. It shipped as a hand-run CREATE in sql/portal/separate.sql only, so a server that
 * never ran it had no table; this creates it there and seeds the five it replaced. Added only where
 * missing, so a database that already edits its own list keeps it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const SEED = [
        '01 Vacation Leave',
        '02 Sick Leave',
        '03 Emergency Leave',
        '04 Bereavement Leave',
        '05 Maternity or Paternity Leave',
    ];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if (! $schema->hasTable('leave_types')) {
            $schema->create('leave_types', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 100);
                $table->timestamp('created_at')->useCurrent();
                $table->unique('name', 'leave_types_name');
            });
        }

        foreach (self::SEED as $name) {
            DB::connection('portal')->table('leave_types')->insertOrIgnore(['name' => $name]);
        }
    }

    public function down(): void
    {
        // The table predates this migration on most databases; dropping it would delete a live list.
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
