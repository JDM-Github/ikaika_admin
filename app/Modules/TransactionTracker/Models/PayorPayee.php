<?php

namespace App\Modules\TransactionTracker\Models;

use App\Modules\Support\ProductModel;

/**
 * Stands alone: the Airtable table has no real links, and its
 * `associated_transactions_text` is free text rather than a relationship.
 */
class PayorPayee extends ProductModel
{
    protected $table = 'payor_payee';

    public static function productKey(): string
    {
        return 'transaction-tracker';
    }
}
