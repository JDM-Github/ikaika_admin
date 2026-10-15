<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * teams: the offices a reimbursement line may belong to, editable on Manage / References instead of a
 * hardcoded list. It shipped as a hand-run CREATE in sql/portal/separate.sql only; this creates it and
 * seeds the two it replaced. Added only where missing, so a database that already edits its own keeps it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const SEED = [
        'Angeles Pampanga Office',
        'Cebu Office',
    ];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if (! $schema->hasTable('teams')) {
            $schema->create('teams', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 100);
                $table->timestamp('created_at')->useCurrent();
                $table->unique('name', 'teams_name');
            });
        }

        foreach (self::SEED as $name) {
            DB::connection('portal')->table('teams')->insertOrIgnore(['name' => $name]);
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
