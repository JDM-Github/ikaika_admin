<?php

namespace App\Modules\Portal\Models;

use App\Modules\Support\ProductModel;

class ActivityCode extends ProductModel
{
    protected $table = 'activity_codes';

    public static function productKey(): string
    {
        return 'portal';
    }
}
