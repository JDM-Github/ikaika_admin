<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Client Information (source) record id of a client (2026-10-22, user). airtable_record_id stays
 * the Users & Projects synced-copy id used by project Client links.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('clients') && ! $schema->hasColumn('clients', 'client_info_airtable_record_id')) {
            $schema->table('clients', function (Blueprint $table): void {
                $table->string('client_info_airtable_record_id', 20)->nullable()->unique()->after('airtable_record_id');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('clients') && $schema->hasColumn('clients', 'client_info_airtable_record_id')) {
            $schema->table('clients', function (Blueprint $table): void {
                $table->dropUnique(['client_info_airtable_record_id']);
                $table->dropColumn('client_info_airtable_record_id');
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
