<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalManageUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    public function __construct(private readonly PortalManageUsers $users) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->users->list($request));
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->users->updateRole(
            $actor,
            $id,
            PortalManageUsers::validatedRole($request),
            $request,
        ));
    }
}
