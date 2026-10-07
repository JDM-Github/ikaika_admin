<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project Approver moves under Manage, beside the page it opens (Project Management), after Users as
 * the sidebar orders them (2026-10-07, user). The key stays projects.manager, so every holder row keeps
 * working. A power counts only under a held parent, so Fin and Audie, who hold no Manage otherwise,
 * gain the Manage group as a personal default; it opens nothing alone. Mirrored in separate.sql.
 */
return new class extends Migration
{
    private const APPROVERS = ['220825-0004', '230103-0005'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        $portal->table('access_permissions')->where('permission_key', 'projects.manager')->update(['parent_key' => 'manage', 'sort_order' => 850]);
        foreach ($this->ids() as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => $id, 'permission_key' => 'manage', 'reach' => null]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        $portal->table('access_permissions')->where('permission_key', 'projects.manager')->update(['parent_key' => 'projects', 'sort_order' => 225]);
        $portal->table('access_personal')->where('permission_key', 'manage')->whereIn('employee_id', $this->ids())->delete();
    }

    /** @return list<int> */
    private function ids(): array
    {
        return DB::connection('portal')->table('employees')->whereIn('id_no', self::APPROVERS)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
