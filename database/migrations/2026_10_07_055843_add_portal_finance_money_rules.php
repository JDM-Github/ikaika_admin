<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance moves money now: a new Accounts page that adds funds to an account and transfers between
 * accounts, envelope-to-envelope transfers, and transactions added by hand. Each is its own power,
 * held by admins, executives and developers like the rest of Finance. The same rows and presets live
 * in sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const RULES = [
        ['finance.accounts', 'finance', 'Accounts', 'See every account with what it received, spent and holds, and the fund transfers between accounts.', 858],
        ['finance.accounts.funds', 'finance.accounts', 'Add and transfer funds', 'Add money to an account, move money between accounts, and complete or cancel a pending transfer.', 859],
        ['finance.envelopes.transfer', 'finance.envelopes', 'Transfer funds', 'Move money from one envelope to another, across their owners\' accounts when those differ.', 860],
        ['finance.transactions.add', 'finance.transactions', 'Add transactions', 'Add income or an expense. An expense on an envelope another account owns leaves a transfer pending to the account that paid.', 861],
    ];

    private const ROLES = ['admin', 'executive', 'developer'];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('access_permissions')) {
            return;
        }

        $portal = DB::connection('portal');
        if (! $portal->table('access_permissions')->where('permission_key', 'finance')->exists()) {
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

        // Children first, so no rule is left pointing at a parent that is gone.
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
