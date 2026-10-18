<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_messages.from_address (2026-10-15, user): a "send as me" mail can leave from the sender's
 * own address instead of the portal's noreply. The From address is stored with the row so a queued
 * copy rebuilt later sends exactly as it was composed. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('email_messages') || $schema->hasColumn('email_messages', 'from_address')) {
            return;
        }
        $schema->table('email_messages', function (Blueprint $table): void {
            $table->string('from_address', 191)->nullable()->after('reply_to');
        });
    }

    public function down(): void
    {
        // Optional data on every row; removing it would drop what each send left from.
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
