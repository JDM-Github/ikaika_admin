<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * My information moved from Settings to Profile, so a profile-change decision opened Settings and
 * not the details it was about (2026-10-19, user). Notices already sent now open Profile too.
 */
return new class extends Migration
{
    private const TYPES = ['profile.changes.approved', 'profile.changes.rejected'];

    public function up(): void
    {
        $this->repoint('/settings', '/profile', 'Open profile');
    }

    public function down(): void
    {
        $this->repoint('/profile', '/settings', 'Open settings');
    }

    private function repoint(string $from, string $to, string $label): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('notifications')) {
            return;
        }

        DB::connection('portal')->table('notifications')
            ->whereIn('type', self::TYPES)
            ->where('link_path', $from)
            ->update(['link_path' => $to, 'link_label' => $label]);
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
