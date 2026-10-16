<?php

namespace App\Http\Controllers\Api\Portal\User;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProfileChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Their own employee information on Settings, and the changes they ask a head admin to approve (2026-10-14).
class InformationController extends Controller
{
    public function __construct(private readonly PortalProfileChanges $changes) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->changes->mine($this->employee($request)));
    }

    public function submit(Request $request): JsonResponse
    {
        return response()->json($this->changes->submit($this->employee($request), $request), 201);
    }

    public function withdraw(Request $request): JsonResponse
    {
        return response()->json($this->changes->withdraw($this->employee($request), $request));
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $employee;
    }
}
