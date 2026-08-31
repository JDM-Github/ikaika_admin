<?php

namespace App\Modules\Core\Models;

use App\Modules\Support\ProductModel;

class Recycle extends ProductModel
{
    protected $table = 'recycle';

    public static function productKey(): string
    {
        return 'core';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'deleted_by' => 'integer',
            'purges_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
