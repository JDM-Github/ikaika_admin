<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\PortalJwt;
use App\Support\ProductRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProductController extends Controller
{
    public function __construct(private readonly PortalJwt $jwt) {}

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

    public function health(Request $request, string $product): JsonResponse
    {
        $health = ProductRegistry::ping($product);
        $payload = [
            'product' => $product,
            'health' => $health,
        ];

        $header = (string) $request->header('Authorization', '');
        // A down product cannot answer session; returning ok false here would sign the portal out.
        if ($health['ok'] && str_starts_with($header, 'Bearer ')) {
            $payload['session'] = $this->sessionFromBearer(trim(substr($header, 7)));
        }

        return response()->json($payload, $health['ok'] ? 200 : 503);
    }

    /**
     * @return array{ok: bool, reason: ?string}
     */
    private function sessionFromBearer(string $token): array
    {
        if ($token === '') {
            return ['ok' => false, 'reason' => 'expired'];
        }

        try {
            $claims = $this->jwt->parse($token);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $employee = Employee::query()->find($claims['sub'] ?? null);
        if ($employee === null) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $status = strtolower(trim((string) $employee->status));
        if ($status !== 'active') {
            return ['ok' => false, 'reason' => 'expired'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
