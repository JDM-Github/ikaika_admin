<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / Shared Pages on a database that never ran its hand-written SQL. The tables, the shareable
 * page list and the manage.shares rule lived only in sql/portal/separate.sql, and the 2026-10-09
 * migration that opens the page to admins skips itself without that rule -- so the Test Server had no
 * rule, and the sidebar hid the page. Everything here is added only where it is missing, so a database
 * that already has Shared Pages keeps its shares, readiness flags and presets as they are.
 */
return new class extends Migration
{
    /**
     * The pages a share may grant and whether each is cleared for guests, as separate.sql seeds them.
     *
     * @var array<string, int>
     */
    private const SHAREABLE = [
        'home' => 1, 'projects.view' => 1, 'projects.track' => 1, 'projects.created' => 1,
        'projects.manager' => 1, 'projects.references' => 1, 'reports.daily' => 0, 'reports.late' => 0,
        'reports.submitted' => 1, 'reports.submitted.edit' => 0, 'requests.own' => 1, 'requests.leave' => 0,
        'requests.overtime' => 0, 'requests.offset' => 0, 'requests.reimbursement' => 0, 'requests.own.edit' => 0,
        'user.logs' => 1, 'user.recycle' => 1, 'calendar' => 1, 'manage.requests' => 1, 'manage.reports' => 1,
        'manage.users' => 1, 'finance.overview' => 1, 'finance.transactions' => 1, 'finance.envelopes' => 1,
        'finance.accounts' => 1, 'administration.recycle' => 1, 'administration.logs' => 1,
        'administration.actions' => 1, 'administration.missing' => 1, 'administration.emails' => 1,
        'email' => 0, 'email.library' => 1, 'administration.backup' => 0,
    ];

    /**
     * key, parent, label, description, sort order.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const RULES = [
        ['manage.shares', 'manage', 'Shared pages', 'Create read-only guest links to portal pages: pick the pages, hand out the link and its password.', 860],
        ['manage.shares.manage', 'manage.shares', 'Create and revoke', 'Create a guest link, change it, and revoke it.', 861],
        ['manage.shares.password', 'manage.shares', 'New passwords', 'Give a guest link a new password, whose old one stops working at once.', 862],
    ];

    // Admins and developers hold all three by preset, as open_portal_references_and_shared_pages_rules gives them.
    private const PRESET_ROLES = ['admin', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if (! $schema->hasTable('shared_pages')) {
            $schema->create('shared_pages', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('label', 100);
                $table->string('guest_name', 100)->nullable();
                $table->string('code', 64)->unique('shared_pages_code');
                $table->string('password_hash', 255);
                $table->string('password_value', 32)->nullable();
                $table->string('email', 255)->nullable();
                $table->boolean('require_otp')->default(true);
                $table->timestamp('expires_at')->nullable();
                $table->smallInteger('rate_per_minute')->default(60);
                $table->timestamp('revoked_at')->nullable();
                $table->integer('created_by');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('last_used_at')->nullable();
            });
        }
        if (! $schema->hasTable('shared_page_rules')) {
            $schema->create('shared_page_rules', function (Blueprint $table): void {
                $table->unsignedInteger('share_id');
                $table->string('rule_key', 100);
                $table->primary(['share_id', 'rule_key']);
                $table->foreign('share_id')->references('id')->on('shared_pages')->cascadeOnDelete();
            });
        }
        if (! $schema->hasTable('shared_page_otps')) {
            $schema->create('shared_page_otps', function (Blueprint $table): void {
                $table->unsignedInteger('share_id')->primary();
                $table->string('code_hash', 255);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamp('expires_at');
                $table->foreign('share_id')->references('id')->on('shared_pages')->cascadeOnDelete();
            });
        }
        if (! $schema->hasTable('shared_page_visits')) {
            $schema->create('shared_page_visits', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('share_id');
                $table->timestamp('visited_at')->useCurrent();
                $table->string('ip', 45)->nullable();
                $table->foreign('share_id')->references('id')->on('shared_pages')->cascadeOnDelete();
            });
        }
        if (! $schema->hasTable('shared_page_shareable')) {
            $schema->create('shared_page_shareable', function (Blueprint $table): void {
                $table->string('rule_key', 100)->primary();
                $table->boolean('is_ready')->default(false);
            });
        }

        $portal = DB::connection('portal');
        foreach (self::SHAREABLE as $key => $isReady) {
            $portal->table('shared_page_shareable')->insertOrIgnore(['rule_key' => $key, 'is_ready' => $isReady]);
        }

        if (! $schema->hasTable('access_permissions') || ! $portal->table('access_permissions')->where('permission_key', 'manage')->exists()) {
            return;
        }
        foreach (self::RULES as [$key, $parent, $label, $description, $order]) {
            $portal->table('access_permissions')->insertOrIgnore([
                'permission_key' => $key,
                'parent_key' => $parent,
                'label' => $label,
                'description' => $description,
                'supports_reach' => 0,
                'can_toggle' => 1,
                'min_tier' => 'admin',
                'sort_order' => $order,
            ]);
        }
        if (! $schema->hasTable('access_presets')) {
            return;
        }
        foreach (self::PRESET_ROLES as $role) {
            foreach (self::RULES as [$key]) {
                $portal->table('access_presets')->insertOrIgnore([
                    'role' => $role,
                    'permission_key' => $key,
                    'reach' => null,
                    'include_executives' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Most databases had Shared Pages before this ran; dropping it here would delete live shares.
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
