<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailBlocksController extends Controller
{
    public function __construct(private readonly PortalEmail $blocks) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->blocks->list($request));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->blocks->create($actor, $request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->blocks->update($actor, $request, $id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $this->blocks->delete($actor, $request, $id);

        return response()->json(null, 204);
    }
}
