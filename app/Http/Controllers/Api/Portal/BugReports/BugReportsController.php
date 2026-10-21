<?php

namespace App\Http\Controllers\Api\Portal\BugReports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalBugReportDeletion;
use App\Support\Portal\PortalBugReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues & Requests. `store` is anyone's, and anonymous unless the sender chose to be named: only then is
 * who is signed in kept (2026-10-20, user). `index` and
 * `update` are MIS's, behind the bug-reports rule, as are `refreshFromJira`, which only reads Jira, and
 * `archive`, which can be undone; `sendToJira`, `sendAllToJira` and `updateJira` are behind bug-reports.jira,
 * and `destroy` behind bug-reports.delete (2026-10-21, user).
 */
class BugReportsController extends Controller
{
    public function __construct(
        private readonly PortalBugReports $reports,
        private readonly PortalBugReportDeletion $deletion,
    ) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->reports->submit($request, $this->actor($request)), 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->reports->list($request->boolean('withArchived')));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->reports->update($this->actor($request), $id, $request));
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return response()->json($this->reports->archive($this->actor($request), $id, $request));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->deletion->destroy($this->actor($request), $id, $request);

        return response()->json(null, 204);
    }

    public function refreshFromJira(Request $request): JsonResponse
    {
        return response()->json($this->reports->refreshFromJira($request->boolean('withArchived')));
    }

    public function sendToJira(Request $request, int $id): JsonResponse
    {
        return response()->json($this->reports->sendToJira($this->actor($request), $id, $request));
    }

    public function sendAllToJira(Request $request): JsonResponse
    {
        return response()->json($this->reports->sendAllToJira($this->actor($request), $request, $request->boolean('withArchived')));
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
