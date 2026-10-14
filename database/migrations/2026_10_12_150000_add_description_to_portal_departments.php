<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * departments.description, shown and edited on Manage / References. It shipped as a hand-run ALTER in
 * sql/portal/separate.sql only, so a server that never ran it failed every References load with an
 * unknown column; this brings it there, and fills the first sentences only where none is written yet.
 */
return new class extends Migration
{
    private const DESCRIPTIONS = [
        'engineering' => 'Structural and civil engineering work; its projects pick scopes from the WBS tree.',
        'mis' => 'Management information systems: software, data and code-tracked projects.',
        'admin' => 'Company administration and support staff.',
        'general' => 'Shared activity codes every department may report on.',
    ];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('departments')) {
            return;
        }
        if (! $schema->hasColumn('departments', 'description')) {
            $schema->table('departments', function (Blueprint $table): void {
                $table->string('description', 255)->nullable()->after('name');
            });
        }

        foreach (self::DESCRIPTIONS as $name => $description) {
            DB::connection('portal')->table('departments')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->where(static fn ($query) => $query->whereNull('description')->orWhereRaw("TRIM(description) = ''"))
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // The column predates this migration on most databases; removing it would drop written descriptions.
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
