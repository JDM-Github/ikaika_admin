<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company departments and their heads (2026-10-14, user): company_departments names the departments
 * people work in, one head each (MIS: Nathaniel, Engineering: Steven); Admin and General stay project
 * buckets in the departments table. What the heads held as personal defaults becomes the 'head' and
 * 'head:mis' presets, and the MIS head gains Share any page. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const HEADS = ['MIS' => '220202-0002', 'Engineering' => '220302-0003'];

    private const HEAD_PRESETS = [
        ['head', 'manage.users.information', 'all'],
        ['head', 'manage.users.information.edit', null],
        ['head', 'manage.onboarding.promote', null],
        ['head', 'projects.lead', 'all'],
        ['head', 'projects.create.direct', null],
        ['head', 'projects.edit.closed', 'department'],
        ['head:mis', 'manage.users.access.peers', null],
        ['head:mis', 'administration.logs.developers', null],
        ['head:mis', 'administration.actions.sync', null],
        ['head:mis', 'manage.shares.any', null],
    ];

    // The personal defaults the presets replace: id_no => keys.
    private const MOVED = [
        '220202-0002' => [
            'manage.users.access.peers', 'administration.logs.developers', 'administration.actions.sync',
            'manage.users.information', 'projects.lead', 'projects.create.direct', 'projects.edit.closed',
            'manage.onboarding.promote', 'manage.users.information.edit',
        ],
        '220302-0003' => [
            'manage.users.information', 'projects.lead', 'projects.create.direct', 'projects.edit.closed',
            'manage.onboarding.promote', 'manage.users.information.edit',
        ],
    ];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        $portal = DB::connection('portal');

        if (! $schema->hasTable('company_departments')) {
            $schema->create('company_departments', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 100)->unique('company_departments_name');
                $table->integer('head_employee_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete();
            });
        }
        foreach (self::HEADS as $name => $idNo) {
            $portal->table('company_departments')->insertOrIgnore([
                'name' => $name,
                'head_employee_id' => $portal->table('employees')->where('id_no', $idNo)->value('id'),
            ]);
        }

        if (! $schema->hasTable('access_permissions') || ! $portal->table('access_permissions')->where('permission_key', 'manage.shares')->exists()) {
            return;
        }
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => 'manage.shares.any',
            'parent_key' => 'manage.shares',
            'label' => 'Share any page',
            'description' => 'Put any ready page on a shared link, at any department or all, even one {UserName|You} switched off or never held.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 863,
        ]);
        $portal->table('access_presets')->insertOrIgnore(['role' => 'developer', 'permission_key' => 'manage.shares.any', 'reach' => null, 'include_executives' => 0]);

        $known = $portal->table('access_permissions')->pluck('permission_key')->all();
        foreach (self::HEAD_PRESETS as [$role, $key, $reach]) {
            if (in_array($key, $known, true)) {
                $portal->table('access_presets')->insertOrIgnore(['role' => $role, 'permission_key' => $key, 'reach' => $reach, 'include_executives' => 0]);
            }
        }

        foreach (self::MOVED as $idNo => $keys) {
            $id = $portal->table('employees')->where('id_no', $idNo)->value('id');
            if ($id !== null) {
                $portal->table('access_personal')->where('employee_id', $id)->whereIn('permission_key', $keys)->delete();
            }
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $portal = DB::connection('portal');

        // The heads take their personal defaults back, at the reach the presets carried.
        $reaches = [];
        foreach (self::HEAD_PRESETS as [, $key, $reach]) {
            $reaches[$key] = $reach;
        }
        foreach (self::MOVED as $idNo => $keys) {
            $id = $portal->table('employees')->where('id_no', $idNo)->value('id');
            foreach ($id === null ? [] : $keys as $key) {
                $portal->table('access_personal')->insertOrIgnore(['employee_id' => $id, 'permission_key' => $key, 'reach' => $reaches[$key] ?? null]);
            }
        }
        $portal->table('access_presets')->whereIn('role', ['head', 'head:mis'])->delete();
        $portal->table('access_presets')->where('permission_key', 'manage.shares.any')->delete();
        $portal->table('access_permissions')->where('permission_key', 'manage.shares.any')->delete();
        Schema::connection('portal')->dropIfExists('company_departments');
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
