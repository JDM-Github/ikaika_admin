<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CC and BCC addresses a send copied. Schema also lives in sql/portal/separate.sql; this adds
 * the columns where the outbox already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('email_messages') && ! $schema->hasColumn('email_messages', 'cc')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->json('cc')->nullable()->after('footer_format');
                $table->json('bcc')->nullable()->after('cc');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasColumn('email_messages', 'cc')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->dropColumn(['cc', 'bcc']);
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
