<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiPath;
use App\Support\ProductNav;
use App\Support\ProductRegistry;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $channel = config('products.channel');

        $products = collect(ProductRegistry::catalog())->map(function (array $product, string $key) {
            $enabled = (bool) ($product['enabled'] ?? false);
            $health = $enabled ? ProductRegistry::ping($key) : [
                'ok' => false,
                'database' => $product['database'] ?? null,
                'error' => 'Product is registered but not enabled.',
            ];
            $module = $enabled ? ProductRegistry::module($key) : null;

            return ProductNav::product($key, $product, $module, $enabled, $health);
        })->values();

        return response()->json([
            'platform' => config('app.name'),
            'channel' => $channel,
            'convention' => ApiPath::convention(),
            'products' => $products,
        ]);
    }
}
