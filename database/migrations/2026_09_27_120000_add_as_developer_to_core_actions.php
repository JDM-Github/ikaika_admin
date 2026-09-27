<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a write a developer made in Developer mode (past their Default powers, or on someone else),
 * so Administration / All Actions can name them the way All Logs does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('core');
        if (! $schema->hasTable('actions') || $schema->hasColumn('actions', 'as_developer')) {
            return;
        }

        $schema->table('actions', function (Blueprint $table): void {
            $table->boolean('as_developer')->default(false)->after('actor_id_no');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('core');
        if (! $schema->hasTable('actions') || ! $schema->hasColumn('actions', 'as_developer')) {
            return;
        }

        $schema->table('actions', function (Blueprint $table): void {
            $table->dropColumn('as_developer');
        });
    }
};
