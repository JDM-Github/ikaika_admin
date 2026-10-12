<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The codes an email sign-in mails: one to the work email and one to the personal email, whichever
 * the employee has. Schema also lives in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('login_otps')) {
            $schema->create('login_otps', function (Blueprint $table): void {
                $table->integer('employee_id')->primary();
                $table->string('work_code_hash', 255)->nullable();
                $table->string('personal_code_hash', 255)->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamp('expires_at');
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('login_otps');
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
