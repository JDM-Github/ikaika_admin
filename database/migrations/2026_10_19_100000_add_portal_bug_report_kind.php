<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issues & Requests (2026-10-19, user): what people send from the corner button is not only bugs but
 * feature requests and feedback too, so a report carries its kind -- bug, feature or feedback -- and the
 * page the bug-reports rule opens is named Issues & Requests on the Access page as everywhere else. The
 * rule key, the tables and the route keep their names, so links, notices and Access Rules hold.
 * Every report sent before this was a bug report, so the column starts at bug.
 * Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'bug-reports';

    private const LABEL = 'Issues & Requests';

    private const DESCRIPTION = 'Read the bugs, feature requests and feedback people send, never who sent them, and track each one to done.';

    private const OLD_LABEL = 'Bug Reports';

    private const OLD_DESCRIPTION = 'Read the bugs people report, never who sent them, and track each one to fixed.';

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (! Schema::connection('portal')->hasColumn('bug_reports', 'kind')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->string('kind', 20)->default('bug')->after('id');
            });
        }
        if (Schema::connection('portal')->hasTable('access_permissions')) {
            DB::connection('portal')->table('access_permissions')
                ->where('permission_key', self::KEY)
                ->update(['label' => self::LABEL, 'description' => self::DESCRIPTION]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        if (Schema::connection('portal')->hasColumn('bug_reports', 'kind')) {
            Schema::connection('portal')->table('bug_reports', function (Blueprint $table): void {
                $table->dropColumn('kind');
            });
        }
        if (Schema::connection('portal')->hasTable('access_permissions')) {
            DB::connection('portal')->table('access_permissions')
                ->where('permission_key', self::KEY)
                ->update(['label' => self::OLD_LABEL, 'description' => self::OLD_DESCRIPTION]);
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
