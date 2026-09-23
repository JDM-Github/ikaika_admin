<?php

namespace App\Http\Controllers\Api\Portal\Projects;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectDeletion;
use App\Support\Portal\PortalViewProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectsController extends Controller
{
    public function __construct(
        private readonly PortalViewProjects $projects,
        private readonly PortalProjectDeletion $deletion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->projects->list($actor));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $this->deletion->destroy($actor, $id, $request);

        return response()->json(null, 204);
    }
}
