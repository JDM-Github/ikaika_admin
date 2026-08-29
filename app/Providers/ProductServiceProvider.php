<?php

namespace App\Providers;

use App\Support\ProductRegistry;
use Illuminate\Support\ServiceProvider;

class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ProductRegistry::registerConnections();
    }
}
