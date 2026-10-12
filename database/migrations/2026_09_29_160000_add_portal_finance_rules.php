<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance (Overview, Transactions, Envelopes) gets rules of its own instead of the admin role. The
 * catalog rows and the presets that keep today's holders (admins, executives, developers) also live in
 * sql/portal/separate.sql for a fresh database; this adds them where the catalog already exists, once.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: ?string, 2: string, 3: string, 4: int}>
     */
    private const RULES = [
        ['finance', null, 'Finance', "See the company's money: budgets, envelopes and every transaction.", 850],
        ['finance.overview', 'finance', 'Overview', 'See the finance dashboard: cash flow, the forecast, team balances and the latest transactions.', 851],
        ['finance.transactions', 'finance', 'Transactions', 'See every transaction in the ledger, with the envelopes and accounts it touched.', 852],
        ['finance.transactions.export', 'finance.transactions', 'Export', 'Download the transactions list.', 853],
        ['finance.envelopes', 'finance', 'Envelopes', 'See every envelope and budget code, with what is left in each.', 854],
        ['finance.envelopes.export', 'finance.envelopes', 'Export', 'Download the envelope and budget code lists.', 855],
    ];

    // Who opened Finance before it had rules: the admin role, which executives and developers also pass.
    private const ROLES = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        $portal = DB::connection('portal');
        foreach (self::RULES as [$key, $parent, $label, $description, $order]) {
            $portal->table('access_permissions')->insertOrIgnore([
                'permission_key' => $key,
                'parent_key' => $parent,
                'label' => $label,
                'description' => $description,
                'supports_reach' => 0,
                'can_toggle' => 1,
                'sort_order' => $order,
            ]);
            foreach (self::ROLES as $role) {
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

        // Children first: the catalog's parent key cascades, but the order keeps it plain.
        foreach (array_reverse(self::RULES) as [$key]) {
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
