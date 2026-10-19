<?php

namespace App\Http\Controllers\Api\Portal\BugReports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalBugReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues & Requests. `store` is anyone's, and anonymous: it never reads who is signed in. `index` and
 * `update` are MIS's, behind the bug-reports rule, as is `refreshFromJira`, which only reads Jira;
 * `sendToJira` and `updateJira` are behind bug-reports.jira.
 */
class BugReportsController extends Controller
{
    public function __construct(private readonly PortalBugReports $reports) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->reports->submit($request), 201);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->reports->list());
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->reports->update($this->actor($request), $id, $request));
    }

    public function refreshFromJira(): JsonResponse
    {
        return response()->json($this->reports->refreshFromJira());
    }

    public function sendToJira(Request $request, int $id): JsonResponse
    {
        return response()->json($this->reports->sendToJira($this->actor($request), $id, $request));
    }

    public function updateJira(Request $request): JsonResponse
    {
        return response()->json($this->reports->updateJira($this->actor($request), $request));
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
