<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Completed or Closed project is its department head's alone (2026-10-12, supervisor), approved or
 * pending. Edit closed projects, under Edit projects, is held at most by admins (min_tier), by no role
 * but developer, and by personal default only by Nathaniel (MIS) and Steven (Engineering), each over
 * their own department. Mirrored in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const HEADS = ['220202-0002', '220302-0003'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'projects.edit')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => 'projects.edit.closed',
            'parent_key' => 'projects.edit',
            'label' => 'Edit closed projects',
            'description' => "Change a Completed or Closed project, approved or not: {UserName|Your} department's or every one. Nobody else can.",
            'supports_reach' => 1,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 215,
        ]);
        $portal->table('access_presets')->insertOrIgnore([
            'role' => 'developer', 'permission_key' => 'projects.edit.closed', 'reach' => 'all', 'include_executives' => 0,
        ]);
        foreach ($this->ids() as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => $id, 'permission_key' => 'projects.edit.closed', 'reach' => 'department']);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        foreach (['access_personal', 'access_grants', 'access_toggles', 'access_presets'] as $table) {
            if (Schema::connection('portal')->hasTable($table)) {
                $portal->table($table)->where('permission_key', 'projects.edit.closed')->delete();
            }
        }
        $portal->table('access_permissions')->where('permission_key', 'projects.edit.closed')->delete();
    }

    /** @return list<int> */
    private function ids(): array
    {
        return DB::connection('portal')->table('employees')->whereIn('id_no', self::HEADS)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
