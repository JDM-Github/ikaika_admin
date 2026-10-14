<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shared page may now be granted at a reach (2026-10-14, user): like View Projects' own reach, the
 * creator picks how far a granted page reaches -- Every department, their department, or narrower --
 * and may never hand out more than they hold. shared_page_rules carries the reach per granted page;
 * shared_pages carries the department a department-reach share is scoped to, snapshotted at save, so
 * the guest stands in with it and the reach filters work. Existing shares granted at all, as before.
 * Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if ($schema->hasTable('shared_page_rules') && ! $schema->hasColumn('shared_page_rules', 'reach')) {
            $schema->table('shared_page_rules', function (Blueprint $table): void {
                $table->string('reach', 16)->nullable()->after('rule_key');
            });
        }
        if ($schema->hasTable('shared_pages') && ! $schema->hasColumn('shared_pages', 'reach_department')) {
            $schema->table('shared_pages', function (Blueprint $table): void {
                $table->string('reach_department', 100)->nullable()->after('rate_per_minute');
            });
        }

        // Every row so far was granted at all; only rules that carry a reach keep it.
        if ($schema->hasTable('shared_page_rules') && $schema->hasTable('access_permissions')) {
            $reachRules = DB::connection('portal')->table('access_permissions')->where('supports_reach', 1)->pluck('permission_key')->all();
            if ($reachRules !== []) {
                DB::connection('portal')->table('shared_page_rules')->whereIn('rule_key', $reachRules)->whereNull('reach')->update(['reach' => 'all']);
            }
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('shared_page_rules') && $schema->hasColumn('shared_page_rules', 'reach')) {
            $schema->table('shared_page_rules', function (Blueprint $table): void {
                $table->dropColumn('reach');
            });
        }
        if ($schema->hasTable('shared_pages') && $schema->hasColumn('shared_pages', 'reach_department')) {
            $schema->table('shared_pages', function (Blueprint $table): void {
                $table->dropColumn('reach_department');
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
