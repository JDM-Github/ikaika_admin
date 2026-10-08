<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding: candidate registration with bound links (docs/power/onboarding-plan.md). Creates the
 * registrations, register_otps and register_documents tables and the manage.onboarding rules with
 * their presets (administrators and developers; executives none). Schema and seeds also live in
 * sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    /**
     * The catalogue rows: key, parent, label, description, sort order. All administrator-tier.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const RULES = [
        ['manage.onboarding', 'manage', 'Onboarding', 'Guest-free candidate registration: send a bound link, collect details and requirements.', 870],
        ['manage.onboarding.manage', 'manage.onboarding', 'Create and revoke', 'Create a candidate entry, change it, revoke it, and promote a submitted one.', 871],
        ['manage.onboarding.password', 'manage.onboarding', 'New passwords', 'Give a candidate link a new password, whose old one stops working at once.', 872],
    ];

    private const ROLES = ['admin', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $this->createTables();

        if (! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        foreach (self::RULES as [$key, $parent, $label, $description, $order]) {
            if ($parent !== 'manage' && ! $portal->table('access_permissions')->where('permission_key', $parent)->exists()) {
                continue;
            }
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

        foreach (self::ROLES as $role) {
            foreach (self::RULES as [$key]) {
                if (! $portal->table('access_permissions')->where('permission_key', $key)->exists()) {
                    continue;
                }
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
        if (! $this->portalReachable()) {
            return;
        }

        if (Schema::connection('portal')->hasTable('access_permissions')) {
            $portal = DB::connection('portal');
            foreach (self::ROLES as $role) {
                $portal->table('access_presets')->where('role', $role)
                    ->whereIn('permission_key', array_column(self::RULES, 0))->delete();
            }
            foreach (array_reverse(self::RULES) as [$key]) {
                $portal->table('access_permissions')->where('permission_key', $key)->delete();
            }
        }

        Schema::connection('portal')->dropIfExists('register_documents');
        Schema::connection('portal')->dropIfExists('register_otps');
        Schema::connection('portal')->dropIfExists('registrations');
    }

    private function createTables(): void
    {
        if (! Schema::connection('portal')->hasTable('registrations')) {
            Schema::connection('portal')->create('registrations', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('candidate_name', 150);
                $table->string('email', 255);
                $table->string('code', 64)->unique('registrations_code');
                $table->string('password_hash', 255);
                // The generated password, kept readable so the administrator can send it again.
                $table->string('password_value', 32)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->integer('created_by');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('promoted_at')->nullable();
                $table->integer('promoted_employee_id')->nullable();
                $table->integer('employee_data_id')->nullable();
                // The step-by-step draft the candidate is filling; kept after submit for reference.
                $table->json('details')->nullable();
                $table->index('email', 'idx_registrations_email');
                $table->foreign('created_by')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('portal')->hasTable('register_otps')) {
            Schema::connection('portal')->create('register_otps', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->integer('registration_id');
                $table->string('code_hash', 255);
                $table->timestamp('expires_at');
                $table->tinyInteger('attempts')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('registration_id')->references('id')->on('registrations')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('portal')->hasTable('register_documents')) {
            Schema::connection('portal')->create('register_documents', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->integer('registration_id');
                $table->string('kind', 24);
                $table->string('name', 191);
                $table->string('mime', 127);
                $table->integer('size_bytes');
                $table->string('path', 255);
                $table->timestamp('uploaded_at')->useCurrent();
                $table->index(['registration_id', 'kind'], 'idx_register_documents_registration');
                $table->foreign('registration_id')->references('id')->on('registrations')->cascadeOnDelete();
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
