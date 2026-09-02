<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRole;
use App\Support\Workspace\WorkspaceGrid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceGridController extends Controller
{
    public function __construct(private readonly WorkspaceGrid $grid) {}

    public function index(Request $request, string $product, string $table): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(
            $this->grid->rows($product, $table, $request, PortalRole::isAdmin($actor->role, $actor->role_level)),
        );
    }

    public function show(Request $request, string $product, string $table, string $id): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(
            $this->grid->record($product, $table, $id, PortalRole::isAdmin($actor->role, $actor->role_level)),
        );
    }

    private function actor(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $actor;
    }
}
