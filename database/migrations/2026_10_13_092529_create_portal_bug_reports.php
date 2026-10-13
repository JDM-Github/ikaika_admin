<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug reports (2026-10-13, user): anyone in the portal reports a bug, with up to three screenshots,
 * and MIS reads them on Bug Reports and tracks each one to fixed. A report is anonymous: nothing in
 * these tables names or points to who sent it -- no employee column, and no time finer than the day,
 * so it cannot be lined up against a log either.
 *
 * The page is the bug-reports rule, held by every MIS member and admin (only_department) and by
 * developers. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    private const KEY = 'bug-reports';

    private const ROLES = ['member', 'admin', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if (! $schema->hasTable('bug_reports')) {
            $schema->create('bug_reports', function (Blueprint $table): void {
                // Pinned: the local server defaults new tables to MyISAM, which has no transactions or keys.
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('title', 160);
                $table->text('description');
                $table->string('page', 200)->nullable();
                $table->string('status', 20)->default('new');
                $table->text('note')->nullable();
                $table->date('reported_on');
                $table->timestamp('updated_at')->nullable();
                $table->index(['status', 'reported_on']);
            });
        }

        if (! $schema->hasTable('bug_report_images')) {
            $schema->create('bug_report_images', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('bug_report_id');
                $table->string('file_url', 500);
                $table->string('mime_type', 100);
                $table->unsignedInteger('size_bytes');
                $table->foreign('bug_report_id')->references('id')->on('bug_reports')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => null,
            'label' => 'Bug Reports',
            'description' => 'Read the bugs people report, never who sent them, and track each one to fixed.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'only_department' => 'MIS',
            'sort_order' => 990,
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
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');
        $schema->dropIfExists('bug_report_images');
        $schema->dropIfExists('bug_reports');
        if ($schema->hasTable('access_permissions')) {
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
