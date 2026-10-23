<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archive and delete in Issues & Requests (2026-10-21, user). An archived report is out of the way behind a
 * filter and comes back as it was; a deleted one sits in its deleter's Recycle Bin for 30 days.
 *
 * Both are stamps, not removals -- archived_at with who did it, and deleted_at as projects keep -- so the
 * screenshots (which cascade from the row), the issue's link and its "(report #N)" mark, the bell's notices and
 * every "Duplicate of #N" stay valid, and a restore is exact.
 *
 * Deleting is a power of its own under Issues & Requests, Delete issues and requests, held by the same people
 * as the page and switchable per person; archiving, which can be undone, is the page's. Mirrored in
 * sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'bug-reports.delete';

    private const ROLES = ['member', 'admin', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (! Schema::connection('portal')->hasColumn('bug_reports', 'deleted_at')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->after('updated_at');
                $table->unsignedInteger('archived_by')->nullable()->after('archived_at');
                $table->timestamp('deleted_at')->nullable()->after('archived_by');
            });
        }

        if (! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'bug-reports',
            'label' => 'Delete issues and requests',
            'description' => 'Delete an issue or request to your Recycle Bin, where it stays for 30 days and can be restored.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'only_department' => 'MIS',
            'sort_order' => 992,
        ]);
        foreach (self::ROLES as $role) {
            $portal->table('access_presets')->insertOrIgnore([
                'role' => $role,
                'permission_key' => self::KEY,
                'reach' => null,
                'include_executives' => 0,
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (Schema::connection('portal')->hasColumn('bug_reports', 'deleted_at')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->dropColumn(['archived_at', 'archived_by', 'deleted_at']);
            });
        }
        if (Schema::connection('portal')->hasTable('access_permissions')) {
            DB::connection('portal')->table('access_presets')->where('permission_key', self::KEY)->delete();
            DB::connection('portal')->table('access_permissions')->where('permission_key', self::KEY)->delete();
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
