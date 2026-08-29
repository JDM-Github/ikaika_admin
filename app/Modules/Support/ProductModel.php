<?php

namespace App\Modules\Support;

use Illuminate\Database\Eloquent\Model;

abstract class ProductModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    abstract public static function productKey(): string;

    public function __construct(array $attributes = [])
    {
        $this->connection = config('products.catalog.'.static::productKey().'.connection');

        parent::__construct($attributes);
    }
}
