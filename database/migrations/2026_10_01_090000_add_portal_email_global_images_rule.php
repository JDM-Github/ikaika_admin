<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global images (adding to and deleting from email-upload/global/) becomes a power of its own under
 * Email: executives hold it by preset, developers in Developer mode, and an admin only when given it.
 * The same row and presets live in sql/portal/separate.sql; this adds them where the catalog exists.
 */
return new class extends Migration
{
    private const KEY = 'email.images.global';

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'email')->exists()) {
            return;
        }
        $portal->table('access_permissions')->insertOrIgnore([
            'permission_key' => self::KEY,
            'parent_key' => 'email',
            'label' => 'Global images',
            'description' => 'Add and delete the images everyone can use in an email. Anyone who can open Email can already use them. Only administrators can hold it.',
            'supports_reach' => 0,
            'can_toggle' => 1,
            'min_tier' => 'admin',
            'sort_order' => 983,
        ]);
        foreach (['executive', 'developer'] as $role) {
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
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        DB::connection('portal')->table('access_permissions')->where('permission_key', self::KEY)->delete();
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
