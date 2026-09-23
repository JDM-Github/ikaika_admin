<?php

namespace App\Http\Controllers\Api\Portal\Requests;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalReimbursementRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReimbursementController extends Controller
{
    public function __construct(private readonly PortalReimbursementRequests $reimbursements) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reimbursements->list($actor, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reimbursements->create($actor, $request), 201);
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reimbursements->storeReceipt($actor, $request), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reimbursements->replace($actor, $id, $request));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reimbursements->cancel($actor, $id, $request));
    }
}
