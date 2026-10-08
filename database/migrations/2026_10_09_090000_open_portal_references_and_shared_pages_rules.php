<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manage / References and Manage / Shared Pages become ordinary administrator-tier powers instead of
 * Developer-mode-only tools (2026-10-09, user): both rules open to admins, References gains its edit
 * key, and Shared Pages gains manage and password keys. Admins and developers hold everything by
 * preset; executives none, as before. Mirrored in sql/portal/separate.sql for a fresh database.
 */
return new class extends Migration
{
    /**
     * The new catalogue rows: key, parent, label, description, sort order.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const RULES = [
        ['projects.references.edit', 'projects.references', 'Edit references', 'Add, change and remove the scopes, project types, departments and clients.', 241],
        ['manage.shares.manage', 'manage.shares', 'Create and revoke', 'Create a guest link, change it, and revoke it.', 861],
        ['manage.shares.password', 'manage.shares', 'New passwords', 'Give a guest link a new password, whose old one stops working at once.', 862],
    ];

    /** The two base rows that move off Developer mode. */
    private const OPENED = ['projects.references', 'manage.shares'];

    /**
     * The preset rows this migration owns, so down() removes exactly them.
     *
     * @var array<string, list<string>>
     */
    private const PRESETS = [
        'admin' => ['projects.references', 'projects.references.edit', 'manage.shares', 'manage.shares.manage', 'manage.shares.password'],
        // projects.references already sits in the developer preset.
        'developer' => ['projects.references.edit', 'manage.shares', 'manage.shares.manage', 'manage.shares.password'],
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        foreach (self::RULES as [$key, $parent, $label, $description, $order]) {
            // A child needs its parent row: Shared Pages may not have been deployed everywhere yet.
            if (! $portal->table('access_permissions')->where('permission_key', $parent)->exists()) {
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

        $portal->table('access_permissions')->whereIn('permission_key', self::OPENED)->update(['min_tier' => 'admin']);

        foreach (self::PRESETS as $role => $keys) {
            foreach ($keys as $key) {
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
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }
        $portal = DB::connection('portal');

        foreach (self::PRESETS as $role => $keys) {
            $portal->table('access_presets')->where('role', $role)->whereIn('permission_key', $keys)->delete();
        }

        $portal->table('access_permissions')->whereIn('permission_key', self::OPENED)->update(['min_tier' => 'developer']);

        // Children first: the catalog's parent key cascades, but the order keeps it plain.
        foreach (array_reverse(self::RULES) as [$key]) {
            $portal->table('access_permissions')->where('permission_key', $key)->delete();
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
