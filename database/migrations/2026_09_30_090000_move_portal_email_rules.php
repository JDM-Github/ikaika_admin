<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The email rules move to match the sidebar: Email is a page of its own at the top level (sending,
 * templates and footers under it), and All Emails is an Administration page with its Export. Every
 * preset, personal default, gift, limit, switch, reach and list on an old key moves to its new key, so
 * nobody gains or loses anything. The new rows also live in sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * Old key => [new key, parent, label, description, sort order], parents before children.
     *
     * @var array<string, array{0: string, 1: ?string, 2: string, 3: string, 4: int}>
     */
    private const MOVES = [
        'administration.email' => ['email', null, 'Email', 'Open the Email page: write an email and see how it lands before it goes.', 980],
        'administration.email.send' => ['email.send', 'email', 'Send emails', 'Send the email to everyone, a department, a role, chosen members or typed addresses.', 981],
        'administration.email.library' => ['email.library', 'email', 'Templates and footers', 'Save, edit and delete email templates and footers.', 982],
        'administration.email.outbox' => ['administration.emails', 'administration', 'All Emails', 'See every email the portal sent, who it reached and whether it arrived.', 950],
        'administration.email.export' => ['administration.emails.export', 'administration.emails', 'Export', 'Download the list of sent emails.', 951],
    ];

    // Every table that names a rule; each row keeps its other columns as they were.
    private const HOLDERS = ['access_presets', 'access_personal', 'access_grants', 'access_limits', 'access_toggles', 'access_self_reach', 'access_lists'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        DB::connection('portal')->transaction(function (): void {
            foreach (self::MOVES as $old => [$new, $parent, $label, $description, $order]) {
                $this->move($old, [
                    'permission_key' => $new,
                    'parent_key' => $parent,
                    'label' => $label,
                    'description' => $description,
                    'sort_order' => $order,
                ]);
            }
            $this->dropOld(array_keys(self::MOVES));
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        $back = [
            'email' => ['administration.email', 'administration', 'Send Email', 'Open the Send Email page.', 950],
            'email.send' => ['administration.email.send', 'administration.email', 'Send emails', 'Write and send an email to everyone, a department, a role or chosen members.', 951],
            'email.library' => ['administration.email.library', 'administration.email', 'Templates and footers', 'Create, edit and delete email templates and footers.', 952],
            'administration.emails' => ['administration.email.outbox', 'administration.email', 'Outbox', 'See every email that went out.', 953],
            'administration.emails.export' => ['administration.email.export', 'administration.email', 'Export', 'Download the outbox.', 954],
        ];
        DB::connection('portal')->transaction(function () use ($back): void {
            foreach ($back as $old => [$new, $parent, $label, $description, $order]) {
                $this->move($old, [
                    'permission_key' => $new,
                    'parent_key' => $parent,
                    'label' => $label,
                    'description' => $description,
                    'sort_order' => $order,
                ]);
            }
            $this->dropOld(array_keys($back));
        });
    }

    /**
     * @param  array{permission_key: string, parent_key: ?string, label: string, description: string, sort_order: int}  $row
     */
    private function move(string $old, array $row): void
    {
        $portal = DB::connection('portal');
        $current = $portal->table('access_permissions')->where('permission_key', $old)->first();
        if ($current === null) {
            return;
        }

        $portal->table('access_permissions')->insertOrIgnore([...(array) $current, ...$row]);
        foreach (self::HOLDERS as $table) {
            if (! Schema::connection('portal')->hasTable($table)) {
                continue;
            }
            foreach ($portal->table($table)->where('permission_key', $old)->get() as $holder) {
                $copy = (array) $holder;
                unset($copy['id']);
                $copy['permission_key'] = $row['permission_key'];
                $portal->table($table)->insertOrIgnore($copy);
            }
        }
    }

    /**
     * Children first, so no old row is left pointing at a parent that is gone.
     *
     * @param  list<string>  $keys
     */
    private function dropOld(array $keys): void
    {
        foreach (array_reverse($keys) as $key) {
            DB::connection('portal')->table('access_permissions')->where('permission_key', $key)->delete();
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
