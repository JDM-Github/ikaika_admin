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

    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $request->attributes->get('portalEmployee');
        if (! $viewer instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->users->profile($viewer, $id, $request));
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

    // What Add user offers: the next ID No. and the departments the actor's Users reach covers.
    public function newUser(Request $request): JsonResponse
    {
        return response()->json($this->users->newUserOptions($this->actor($request), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->users->addUser($this->actor($request), $request), 201);
    }

    public function updateDepartment(Request $request, int $id): JsonResponse
    {
        return response()->json($this->users->changeDepartment($this->actor($request), $id, $request));
    }

    public function updateProfile(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phoneNumber' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'footerId' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->users->updateProfile($actor, $id, $validated, $request));
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
