<?php

namespace App\Modules\TransactionTracker;

use App\Modules\Support\ProductModule;

/**
 * The transaction tracker: budget envelopes and the transactions that fund and spend them, migrated
 * from the "Transaction Tracker" Airtable base.
 *
 * No resources of its own yet. The portal reads envelopes and writes reimbursement charges through
 * the `tracker` connection this product's catalog entry registers, so what this module exposes is
 * only the health check the catalog adds to every product.
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
        return [];
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
