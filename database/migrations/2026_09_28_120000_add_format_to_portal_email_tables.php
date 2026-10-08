<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a block or a sent email is the toolbar's markup or HTML sent as written. Schema also lives
 * in sql/portal/separate.sql; this adds the columns where the email tables already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('email_blocks') && ! $schema->hasColumn('email_blocks', 'format')) {
            $schema->table('email_blocks', function (Blueprint $table): void {
                $table->string('format', 8)->default('markup')->after('body');
            });
        }
        if ($schema->hasTable('email_messages') && ! $schema->hasColumn('email_messages', 'format')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->string('format', 8)->default('markup')->after('body');
                $table->string('footer_format', 8)->nullable()->after('footer_body');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasColumn('email_messages', 'format')) {
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->dropColumn(['format', 'footer_format']);
            });
        }
        if ($schema->hasColumn('email_blocks', 'format')) {
            $schema->table('email_blocks', function (Blueprint $table): void {
                $table->dropColumn('format');
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
