<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allowed activities per department (2026-10-13, user): Management is a project type in Admin, Engineering,
 * General and MIS, each with its own list, so a row may name its department and then wins over the shared
 * row (no department, as every Airtable row is). Seeds the four Management lists. Also in separate.sql.
 */
return new class extends Migration
{
    /** Department => the codes its Management type may report on. */
    private const MANAGEMENT = [
        'Engineering' => '1004',
        'MIS' => '2002,2004',
        'General' => '2001,2003,9000,9001,9002',
        'Admin' => '2001,2003,9000,9001,9002',
    ];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('job_type_allowed_activities')) {
            return;
        }
        if (! $schema->hasColumn('job_type_allowed_activities', 'department')) {
            $schema->table('job_type_allowed_activities', function (Blueprint $table): void {
                $table->string('department', 100)->nullable()->after('job_type');
            });
        }
        $portal = DB::connection('portal');
        foreach (self::MANAGEMENT as $department => $codes) {
            $exists = $portal->table('job_type_allowed_activities')->where('job_type', 'Management')->where('department', $department)->exists();
            if (! $exists) {
                $portal->table('job_type_allowed_activities')->insert(['job_type' => 'Management', 'department' => $department, 'activity_code_id_initials' => $codes]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasColumn('job_type_allowed_activities', 'department')) {
            return;
        }
        DB::connection('portal')->table('job_type_allowed_activities')->whereNotNull('department')->delete();
        Schema::connection('portal')->table('job_type_allowed_activities', function (Blueprint $table): void {
            $table->dropColumn('department');
        });
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
