<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profile changes (2026-10-14, user): anyone changes their own employee information and documents on
 * Settings, and the change waits here until a head admin approves it; only then does it reach their
 * record. A request keeps the details asked for, the files put on Cloudinary for it and the files to
 * take off, and how and by whom it was decided.
 *
 * Approve profile changes sits under Show employee information, held by developers by preset and by
 * Nathaniel Tanglao and Steven Gaurano, the head admins, by their own defaults. Mirrored in
 * sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'manage.users.information.approve';

    private const ROLES = ['developer'];

    private const PERSONAL = [
        '220202-0002', // Nathaniel Tanglao
        '220302-0003', // Steven Gaurano
    ];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if (! $schema->hasTable('employee_change_requests')) {
            $schema->create('employee_change_requests', function (Blueprint $table): void {
                // Pinned: the local server defaults new tables to MyISAM, which has no transactions or keys.
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('employee_id');
                $table->string('status', 20)->default('Pending');
                $table->json('fields')->nullable();
                $table->json('documents')->nullable();
                $table->dateTime('submitted_at');
                $table->dateTime('decided_at')->nullable();
                $table->unsignedInteger('decided_by')->nullable();
                $table->string('remarks', 500)->nullable();
                $table->index(['employee_id', 'status']);
                $table->index(['status', 'submitted_at']);
            });
        }

        if (! $schema->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'manage.users.information')->exists()) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'manage.users.information',
            'label' => 'Approve profile changes',
            'description' => 'Approve or reject the employee information and documents people change about themselves on Settings, before it reaches their record.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 849,
        ]);
        foreach (self::ROLES as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => self::KEY,
                'reach' => null,
                'include_executives' => 0,
            ]);
        }
        foreach ($portal->table('employees')->whereIn('id_no', self::PERSONAL)->pluck('id') as $id) {
            $portal->table('access_personal')->insertOrIgnore(['employee_id' => (int) $id, 'permission_key' => self::KEY, 'reach' => null]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        if ($schema->hasTable('access_permissions')) {
            $portal = DB::connection('portal');
            $portal->table('access_personal')->where('permission_key', self::KEY)->delete();
            $portal->table('access_presets')->where('permission_key', self::KEY)->delete();
            $portal->table('access_permissions')->where('permission_key', self::KEY)->delete();
        }
        $schema->dropIfExists('employee_change_requests');
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
