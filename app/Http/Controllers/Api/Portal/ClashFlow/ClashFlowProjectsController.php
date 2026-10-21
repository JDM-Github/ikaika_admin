<?php

namespace App\Http\Controllers\Api\Portal\ClashFlow;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalClashFlowProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClashFlowProjectsController extends Controller
{
    public function __invoke(Request $request, PortalClashFlowProjects $projects): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json(['data' => $projects->forEmployee($actor)]);
    }
}
