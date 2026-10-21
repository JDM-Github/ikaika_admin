<?php

namespace App\Http\Controllers\Api\Portal\ClashFlow;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalClashFlowProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClashFlowProjectsController extends Controller
{
    public function __construct(private readonly PortalClashFlowProjects $projects) {}

    // My ClashFlow projects with my role, and whether I may start a team project.
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        // An Admin filters by department (default theirs); everyone else has no department filter.
        $isAdmin = $this->projects->isAdmin($actor);

        return response()->json([
            'data' => $this->projects->forEmployee($actor),
            'canCreateTeam' => $this->projects->canCreateTeam($actor),
            'isAdmin' => $isAdmin,
            'department' => (string) ($actor->department ?? ''),
            'departments' => $isAdmin ? $this->projects->departments() : [],
        ]);
    }

    // Portal projects I may link to a new team project (search by number or name).
    public function linkable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->projects->linkable(
            $this->actor($request),
            (string) ($validated['search'] ?? ''),
            (string) ($validated['department'] ?? ''),
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in([PortalClashFlowProjects::MODE_TEAM, PortalClashFlowProjects::MODE_SOLO])],
            'portalProjectId' => ['required_if:mode,team', 'nullable', 'integer'],
            'name' => ['required_if:mode,solo', 'nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->projects->create($this->actor($request), $validated)], 201);
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
