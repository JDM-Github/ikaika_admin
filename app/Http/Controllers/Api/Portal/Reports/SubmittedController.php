<?php

namespace App\Http\Controllers\Api\Portal\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSubmittedReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmittedController extends Controller
{
    public function __construct(private readonly PortalSubmittedReports $reports) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->list($actor, $request));
    }

    public function days(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->days($actor, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->create($actor, $request), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->replace($actor, $id, $request));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $this->reports->destroy($actor, $id, $request);

        return response()->json(null, 204);
    }
}
