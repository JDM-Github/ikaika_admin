<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Without these a row the Airtable sync keeps failing looks exactly like one never tried.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('core');
        if (! $schema->hasTable('actions')) {
            return;
        }

        if (! $schema->hasColumn('actions', 'attempts')) {
            $schema->table('actions', function (Blueprint $table): void {
                $table->unsignedSmallInteger('attempts')->default(0);
            });
        }

        if (! $schema->hasColumn('actions', 'last_error')) {
            $schema->table('actions', function (Blueprint $table): void {
                $table->text('last_error')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('core');
        if (! $schema->hasTable('actions')) {
            return;
        }

        $columns = array_values(array_filter([
            $schema->hasColumn('actions', 'attempts') ? 'attempts' : null,
            $schema->hasColumn('actions', 'last_error') ? 'last_error' : null,
        ]));
        if ($columns === []) {
            return;
        }

        $schema->table('actions', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
