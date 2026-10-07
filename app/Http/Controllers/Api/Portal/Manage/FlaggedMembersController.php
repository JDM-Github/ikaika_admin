<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalFlaggedMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlaggedMembersController extends Controller
{
    public function __construct(private readonly PortalFlaggedMembers $flags) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->flags->list($this->actor($request), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->flags->flag($this->actor($request), $request), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json($this->flags->unflag($this->actor($request), $request, $id));
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
