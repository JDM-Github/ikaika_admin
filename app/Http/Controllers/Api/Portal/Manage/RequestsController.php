<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalManageRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestsController extends Controller
{
    public function __construct(private readonly PortalManageRequests $requests) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->requests->list($request));
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->requests->decide($actor, $request));
    }
}
