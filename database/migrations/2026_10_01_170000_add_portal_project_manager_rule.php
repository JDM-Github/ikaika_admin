<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / Projects (2026-10-01): the Project Manager power approves the projects waiting for its
 * departments, and members may add (request) projects. Who asked, and when, is kept on the project.
 * The same catalog row, presets and columns live in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    // Everyone who already added projects instantly keeps doing so, for every department.
    private const MANAGER_ROLES = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        $portal = DB::connection('portal');

        if ($schema->hasTable('access_permissions')) {
            $portal->table('access_permissions')->insertOrIgnore([
                'permission_key' => 'projects.manager',
                'parent_key' => 'projects',
                'label' => 'Project Manager',
                'description' => 'Approve or reject the projects waiting on Manage / Projects for {UserName|Your} department or every department, and add projects there without waiting.',
                'supports_reach' => 1,
                'can_toggle' => 1,
                'sort_order' => 225,
            ]);
            foreach (self::MANAGER_ROLES as $role) {
                $portal->table('access_presets')->insertOrIgnore([
                    'role' => $role,
                    'permission_key' => 'projects.manager',
                    'reach' => 'all',
                    'include_executives' => 0,
                ]);
            }
            $portal->table('access_presets')->insertOrIgnore([
                'role' => 'member',
                'permission_key' => 'projects.create',
                'reach' => null,
                'include_executives' => 0,
            ]);
        }

        if ($schema->hasTable('projects') && ! $schema->hasColumn('projects', 'requested_by')) {
            $schema->table('projects', function (Blueprint $table): void {
                $table->integer('requested_by')->nullable();
                $table->timestamp('requested_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_presets')->where('role', 'member')->where('permission_key', 'projects.create')->delete();
        $portal->table('access_permissions')->where('permission_key', 'projects.manager')->delete();
        if (Schema::connection('portal')->hasColumn('projects', 'requested_by')) {
            Schema::connection('portal')->table('projects', function (Blueprint $table): void {
                $table->dropColumn(['requested_by', 'requested_at']);
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
