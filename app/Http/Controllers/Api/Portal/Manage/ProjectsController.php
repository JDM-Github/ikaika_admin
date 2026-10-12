<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalManageProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectsController extends Controller
{
    public function __construct(private readonly PortalManageProjects $projects) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->projects->list($this->actor($request), $request));
    }

    // One project, every field the Add / Edit Project form carries, for the details page.
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->projects->show($this->actor($request), $request, $id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->projects->decide($this->actor($request), $request, $id));
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
