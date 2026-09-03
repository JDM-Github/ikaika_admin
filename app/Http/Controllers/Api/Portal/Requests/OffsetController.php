<?php

namespace App\Http\Controllers\Api\Portal\Requests;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalOffsetRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OffsetController extends Controller
{
    public function __construct(private readonly PortalOffsetRequests $offset) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->offset->list($actor, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->offset->create($actor, $request), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->offset->replace($actor, $id, $request));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->offset->cancel($actor, $id));
    }
}
