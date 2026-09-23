<?php

namespace App\Modules\TransactionTracker\Models;

use App\Modules\Support\ProductModel;

/**
 * `payor_payee` here is free text carried over from Airtable, not a foreign key
 * into PayorPayee -- see sql/transaction_tracker/schema.sql note 4.
 */
class Transaction extends ProductModel
{
    protected $table = 'transactions';

    public static function productKey(): string
    {
        return 'transaction-tracker';
    }
}
