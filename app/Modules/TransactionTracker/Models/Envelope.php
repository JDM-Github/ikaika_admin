<?php

namespace App\Modules\TransactionTracker\Models;

use App\Modules\Support\ProductModel;

/**
 * Airtable table "Envelopes - Budget". `group_name` is its "Group" field,
 * renamed because GROUP is reserved in MySQL.
 */
class Envelope extends ProductModel
{
    protected $table = 'envelopes_budget';

    public static function productKey(): string
    {
        return 'transaction-tracker';
    }
}
