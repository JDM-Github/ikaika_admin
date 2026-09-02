<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRole;
use App\Support\Workspace\WorkspaceSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceSchemaController extends Controller
{
    public function __construct(private readonly WorkspaceSchema $schema) {}

    public function index(Request $request, string $product): JsonResponse
    {
        $this->actor($request);

        return response()->json($this->schema->nav($product));
    }

    public function show(Request $request, string $product, string $table): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(
            $this->schema->table($product, $table, PortalRole::isAdmin($actor->role, $actor->role_level)),
        );
    }

    /**
     * Browsing is gated inside the controller rather than by the portal.admin
     * middleware, which reports a refusal to the whole admin roster by email.
     */
    private function actor(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $actor;
    }
}
