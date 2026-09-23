<?php

namespace App\Modules\TransactionTracker\Models;

use App\Modules\Support\ProductModel;

class BudgetCode extends ProductModel
{
    protected $table = 'budget_codes';

    public static function productKey(): string
    {
        return 'transaction-tracker';
    }
}
