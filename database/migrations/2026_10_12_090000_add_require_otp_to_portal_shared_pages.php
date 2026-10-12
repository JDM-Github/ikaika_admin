<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a share with a bound email also asks for a mailed code at sign-in. Existing shares keep
 * asking (default 1). Schema also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('shared_pages') && ! $schema->hasColumn('shared_pages', 'require_otp')) {
            $schema->table('shared_pages', function (Blueprint $table): void {
                $table->boolean('require_otp')->default(true)->after('email');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasColumn('shared_pages', 'require_otp')) {
            $schema->table('shared_pages', function (Blueprint $table): void {
                $table->dropColumn('require_otp');
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
