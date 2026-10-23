<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind Microsoft sign-in to Entra's immutable object id (2026-10-23 security audit F-002).
 * Nullable until an operator links the account; unique so one Entra account cannot own two
 * portal employees. Schema also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('employees') && ! $schema->hasColumn('employees', 'microsoft_oid')) {
            $schema->table('employees', function (Blueprint $table): void {
                $table->string('microsoft_oid', 64)->nullable()->unique()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if ($schema->hasTable('employees') && $schema->hasColumn('employees', 'microsoft_oid')) {
            $schema->table('employees', function (Blueprint $table): void {
                $table->dropUnique(['microsoft_oid']);
                $table->dropColumn('microsoft_oid');
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
