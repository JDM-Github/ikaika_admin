<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Calendar (source) record id of an earn code (2026-10-22, user). airtable_record_id stays the
 * Users & Projects synced-copy id used by report/request links; calendar_airtable_record_id is the
 * record the portal edits in the editable Calendar base.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('earn_codes') && ! $schema->hasColumn('earn_codes', 'calendar_airtable_record_id')) {
            $schema->table('earn_codes', function (Blueprint $table): void {
                $table->string('calendar_airtable_record_id', 20)->nullable()->unique()->after('airtable_record_id');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('earn_codes') && $schema->hasColumn('earn_codes', 'calendar_airtable_record_id')) {
            $schema->table('earn_codes', function (Blueprint $table): void {
                $table->dropUnique(['calendar_airtable_record_id']);
                $table->dropColumn('calendar_airtable_record_id');
            });
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
