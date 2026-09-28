<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a send went to, name and address, so All Emails can list them. Schema also lives in
 * sql/portal/separate.sql; this adds the column where the outbox already exists. Older sends keep
 * it null: they never recorded their list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('email_messages') && ! $schema->hasColumn('email_messages', 'recipients')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->json('recipients')->nullable()->after('audience_filter');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasColumn('email_messages', 'recipients')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->dropColumn('recipients');
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
