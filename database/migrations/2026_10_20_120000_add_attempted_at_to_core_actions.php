<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the Airtable sync last tried an action that did not land (2026-10-20): the scheduled sync waits
 * from that try, not from when the action was written, so an old action is not retried every minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('core');
        if (! $schema->hasTable('actions') || $schema->hasColumn('actions', 'attempted_at')) {
            return;
        }

        $schema->table('actions', function (Blueprint $table): void {
            $table->timestamp('attempted_at')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('core');
        if (! $schema->hasTable('actions') || ! $schema->hasColumn('actions', 'attempted_at')) {
            return;
        }

        $schema->table('actions', function (Blueprint $table): void {
            $table->dropColumn('attempted_at');
        });
    }
};
