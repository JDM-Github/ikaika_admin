<?php

namespace App\Modules\Core\Models;

use App\Modules\Support\ProductModel;

class Setting extends ProductModel
{
    protected $table = 'settings';

    public static function productKey(): string
    {
        return 'core';
    }
}
