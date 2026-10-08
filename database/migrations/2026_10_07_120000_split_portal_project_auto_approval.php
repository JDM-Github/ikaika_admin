<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-approve is no longer every admin's (2026-10-07, supervisor). Add without approval becomes
 * Auto-approve projects, held at most by admins (min_tier) and by default only by Nathaniel and Steven;
 * Project Manager becomes Project Approver, which approves others' projects but no longer passes its
 * holder's own, and reaches projects like the other project powers (lead, own, department, all).
 * Every admin keeps Approver by preset; Fin Donasco and Audie Gantuangko gain it over the projects they lead. The same rows live in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    private const AUTO_APPROVERS = ['220202-0002', '220302-0003'];

    private const APPROVERS = ['220825-0004', '230103-0005'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        $portal->table('access_permissions')->where('permission_key', 'projects.create.direct')->update([
            'label' => 'Auto-approve projects',
            'description' => 'A project {UserName|You} add is approved at once, without waiting for a Project Approver.',
            'min_tier' => 'admin',
        ]);
        $portal->table('access_permissions')->where('permission_key', 'projects.manager')->update([
            'label' => 'Project Approver',
            'description' => "Approve or reject the projects waiting on Project Management: the ones {UserName|You} lead, the ones {UserName|You} are on or added, {UserName|Your} department's, or every project. {UserName|Your} own new projects still wait unless {UserName|You} also auto-approve.",
        ]);
        $portal->table('access_presets')->where('permission_key', 'projects.create.direct')->whereIn('role', ['admin', 'executive'])->delete();

        foreach ($this->ids(self::AUTO_APPROVERS) as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => $id, 'permission_key' => 'projects.create.direct', 'reach' => null]);
        }
        foreach ($this->ids(self::APPROVERS) as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => $id, 'permission_key' => 'projects.manager', 'reach' => 'lead']);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        $portal->table('access_permissions')->where('permission_key', 'projects.create.direct')->update([
            'label' => 'Add without approval',
            'description' => 'A project {UserName|You} add is approved at once, without waiting for a Project Manager.',
            'min_tier' => null,
        ]);
        $portal->table('access_permissions')->where('permission_key', 'projects.manager')->update([
            'label' => 'Project Manager',
            'description' => 'Approve or reject the projects waiting on Manage / Projects for {UserName|Your} department or every department, and add projects there without waiting.',
        ]);
        foreach (['admin', 'executive'] as $role) {
            $portal->table('access_presets')->insertOrIgnore(['role' => $role, 'permission_key' => 'projects.create.direct', 'reach' => null, 'include_executives' => 0]);
        }
        $portal->table('access_personal')->where('permission_key', 'projects.create.direct')->whereIn('employee_id', $this->ids(self::AUTO_APPROVERS))->delete();
        $portal->table('access_personal')->where('permission_key', 'projects.manager')->whereIn('employee_id', $this->ids(self::APPROVERS))->delete();
    }

    /** @return list<int> */
    private function ids(array $idNos): array
    {
        return DB::connection('portal')->table('employees')->whereIn('id_no', $idNos)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
