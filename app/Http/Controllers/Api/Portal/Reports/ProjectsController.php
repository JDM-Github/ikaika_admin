<?php

namespace App\Http\Controllers\Api\Portal\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectCodeActivity;
use App\Support\Portal\PortalReportProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectsController extends Controller
{
    public function __construct(
        private readonly PortalReportProjects $projects,
        private readonly PortalProjectCodeActivity $code,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->projects->list($actor));
    }

    // The signed-in person's own commits on the report's day, by project; never anyone else's.
    public function codeActivity(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }
        $day = $request->validate(['day' => ['required', 'date_format:Y-m-d']])['day'];

        return response()->json([
            'section' => 'reports',
            'resource' => 'code-activity',
            'data' => $this->code->forPersonDay((int) $actor->getKey(), $day),
        ]);
    }
}
