<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProductRegistry;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $channel = config('products.channel');

        $products = collect(ProductRegistry::catalog())->map(function (array $product, string $key) use ($channel) {
            $enabled = (bool) ($product['enabled'] ?? false);
            $health = $enabled ? ProductRegistry::ping($key) : [
                'ok' => false,
                'database' => $product['database'] ?? null,
                'error' => 'Product is registered but not enabled.',
            ];

            $module = $enabled ? ProductRegistry::module($key) : null;
            $resources = $module?->resources() ?? [];

            return [
                'key' => $key,
                'name' => $product['name'] ?? $key,
                'description' => $product['description'] ?? null,
                'enabled' => $enabled,
                'connection' => $product['connection'] ?? null,
                'database' => $product['database'] ?? null,
                'health' => $health,
                'base_url' => "/api/{$channel}/{$key}",
                'resources' => collect($resources)->map(fn (array $resource, string $name) => [
                    'name' => $name,
                    'label' => $resource['label'] ?? $name,
                    'url' => "/api/{$channel}/{$key}/{$name}",
                ])->values(),
            ];
        })->values();

        return response()->json([
            'platform' => config('app.name'),
            'channel' => $channel,
            'convention' => '/api/{channel}/{product}/{resource}',
            'products' => $products,
        ]);
    }
}
