<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalManageUserPresenter;
use App\Support\ProductNav;
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

        $nav = ProductNav::product($product, $config, $module, true, $health, withCounts: true);

        return response()->json([
            'product' => $product,
            'name' => $nav['name'],
            'description' => $nav['description'],
            'connection' => $nav['connection'],
            'database' => $nav['database'],
            'health' => $health,
            'auth' => $nav['auth'],
            'utilities' => $nav['utilities'],
            'resources' => $nav['resources'],
            'sections' => $nav['sections'],
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

        $employee = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->find($claims['sub'] ?? null);
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
