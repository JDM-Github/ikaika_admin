<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a send went out as, when an administrator sent it as themselves. Schema also lives in
 * sql/portal/separate.sql; this adds the columns where the outbox already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('email_messages') && ! $schema->hasColumn('email_messages', 'from_name')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->string('from_name', 191)->nullable()->after('bcc');
                $table->string('reply_to', 191)->nullable()->after('from_name');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasColumn('email_messages', 'from_name')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->dropColumn(['from_name', 'reply_to']);
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
