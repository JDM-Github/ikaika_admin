<?php

namespace App\Modules\TransactionTracker;

use App\Modules\Support\ProductModule;
use App\Modules\TransactionTracker\Models\Account;
use App\Modules\TransactionTracker\Models\BudgetCode;
use App\Modules\TransactionTracker\Models\Envelope;
use App\Modules\TransactionTracker\Models\PayorPayee;
use App\Modules\TransactionTracker\Models\Transaction;

/**
 * Finance tracking migrated from Airtable base appp5MogesHN2eVvG. Read-only for
 * now: generic resource reads plus the workspace grid, no product-specific
 * routes or auth of its own.
 */
class TransactionTrackerModule implements ProductModule
{
    public function key(): string
    {
        return 'transaction-tracker';
    }

    public function name(): string
    {
        return 'Transaction Tracker';
    }

    public function resources(): array
    {
        return [
            'transactions' => [
                'model' => Transaction::class,
                'label' => 'Transactions',
                'searchable' => ['transaction_name', 'payor_payee', 'description'],
            ],
            'accounts' => [
                'model' => Account::class,
                'label' => 'Accounts',
                'searchable' => ['account_name', 'institution'],
            ],
            'envelopes' => [
                'model' => Envelope::class,
                'label' => 'Envelopes / Budgets',
                'searchable' => ['envelope_name', 'group_name'],
            ],
            'budget-codes' => [
                'model' => BudgetCode::class,
                'label' => 'Budget Codes',
                'searchable' => ['name', 'description'],
            ],
            'payor-payee' => [
                'model' => PayorPayee::class,
                'label' => 'Payors / Payees',
                'searchable' => ['name', 'company'],
            ],
        ];
    }

    public function sections(): array
    {
        return [];
    }

    public function authOperations(): array
    {
        return [];
    }

    public function utilities(): array
    {
        return [];
    }
}
