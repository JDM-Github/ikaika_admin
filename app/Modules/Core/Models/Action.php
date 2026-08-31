<?php

namespace App\Modules\Core\Models;

use App\Modules\Support\ProductModel;

class Action extends ProductModel
{
    protected $table = 'actions';

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
            'parameters' => 'array',
            'actor_id' => 'integer',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
