<?php

namespace App\Modules\TransactionTracker\Models;

use App\Modules\Support\ProductModel;

class Account extends ProductModel
{
    protected $table = 'accounts';

    public static function productKey(): string
    {
        return 'transaction-tracker';
    }
}
