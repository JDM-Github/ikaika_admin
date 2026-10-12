<?php

namespace App\Http\Controllers\Api\Portal\Projects;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalManageProjects;
use App\Support\Portal\PortalProjectActivity;
use App\Support\Portal\PortalProjectDeletion;
use App\Support\Portal\PortalProjectEditor;
use App\Support\Portal\PortalViewProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectsController extends Controller
{
    public function __construct(
        private readonly PortalViewProjects $projects,
        private readonly PortalProjectDeletion $deletion,
        private readonly PortalProjectEditor $editor,
        private readonly PortalProjectActivity $activity,
        private readonly PortalManageProjects $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->projects->list($actor, $request));
    }

    public function track(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->projects->track($actor, $request));
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->approvals->mine($this->actor($request), $request));
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->editor->options($this->actor($request), $request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->editor->show($this->actor($request), $request, $id));
    }

    public function activity(Request $request, int $id): JsonResponse
    {
        return response()->json($this->activity->show($this->actor($request), $request, $id));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->editor->create($this->actor($request), $request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->editor->update($this->actor($request), $request, $id));
    }

    public function scopes(Request $request, int $id): JsonResponse
    {
        return response()->json($this->editor->replaceScopes($this->actor($request), $request, $id));
    }

    public function allowedActivities(Request $request): JsonResponse
    {
        return response()->json($this->editor->saveAllowedActivities($this->actor($request), $request));
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

    private function actor(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $actor;
    }
}
