<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProductRegistry;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function show(string $product): JsonResponse
    {
        $config = ProductRegistry::get($product);
        $module = ProductRegistry::module($product);
        $health = ProductRegistry::ping($product);
        $channel = config('products.channel');

        $resources = collect($module?->resources() ?? [])->map(function (array $resource, string $name) use ($product, $channel, $health) {
            $count = null;

            if ($health['ok'] && isset($resource['model'])) {
                $count = $resource['model']::query()->count();
            }

            return [
                'name' => $name,
                'label' => $resource['label'] ?? $name,
                'count' => $count,
                'url' => "/api/{$channel}/{$product}/{$name}",
            ];
        })->values();

        return response()->json([
            'product' => $product,
            'name' => $config['name'] ?? $product,
            'description' => $config['description'] ?? null,
            'connection' => $config['connection'] ?? null,
            'database' => $config['database'] ?? null,
            'health' => $health,
            'resources' => $resources,
        ]);
    }

    public function health(string $product): JsonResponse
    {
        $health = ProductRegistry::ping($product);

        return response()->json([
            'product' => $product,
            'health' => $health,
        ], $health['ok'] ? 200 : 503);
    }
}
