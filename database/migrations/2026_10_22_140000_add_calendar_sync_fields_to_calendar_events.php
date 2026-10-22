<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Calendar base's Events fields the portal can mirror (2026-10-22, user): the record id written
 * back after a create, the author, specific member names, department names, and full date-times.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('calendar_events')) {
            return;
        }
        $schema->table('calendar_events', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('calendar_events', 'airtable_record_id')) {
                $table->string('airtable_record_id', 20)->nullable()->unique()->after('id');
            }
            if (! $schema->hasColumn('calendar_events', 'author')) {
                $table->string('author', 255)->nullable();
            }
            if (! $schema->hasColumn('calendar_events', 'employee_names')) {
                $table->text('employee_names')->nullable();
            }
            if (! $schema->hasColumn('calendar_events', 'departments')) {
                $table->text('departments')->nullable();
            }
            if (! $schema->hasColumn('calendar_events', 'starts_at_full')) {
                $table->dateTime('starts_at_full')->nullable();
            }
            if (! $schema->hasColumn('calendar_events', 'ends_at_full')) {
                $table->dateTime('ends_at_full')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('calendar_events')) {
            return;
        }
        $schema->table('calendar_events', function (Blueprint $table) use ($schema): void {
            foreach (['airtable_record_id', 'author', 'employee_names', 'departments', 'starts_at_full', 'ends_at_full'] as $column) {
                if ($schema->hasColumn('calendar_events', $column)) {
                    if ($column === 'airtable_record_id') {
                        $table->dropUnique(['airtable_record_id']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
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
