<?php

namespace App\Http\Controllers\Api\Portal\User;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalPlaces;
use App\Support\Portal\PortalProfileChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Their own employee information on Profile, and the changes they ask a head admin to approve (2026-10-14).
// The address pickers read the same Philippine places the registration's do (2026-10-19).
class InformationController extends Controller
{
    public function __construct(
        private readonly PortalProfileChanges $changes,
        private readonly PortalPlaces $places,
    ) {}

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

    public function provinces(Request $request): JsonResponse
    {
        $this->employee($request);

        return response()->json(['data' => $this->places->provinces()]);
    }

    public function cities(Request $request, string $code): JsonResponse
    {
        $this->employee($request);

        return response()->json(['data' => $this->places->cities($code)]);
    }

    public function barangays(Request $request, string $code): JsonResponse
    {
        $this->employee($request);

        return response()->json(['data' => $this->places->barangays($code)]);
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
