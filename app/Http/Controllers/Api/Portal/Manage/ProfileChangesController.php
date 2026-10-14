<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProfileChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The profile changes waiting on Manage / Users, approved or rejected by a head admin (2026-10-14).
class ProfileChangesController extends Controller
{
    public function __construct(private readonly PortalProfileChanges $changes) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->changes->waiting($this->reviewer($request), $request));
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        return response()->json($this->changes->decide($this->reviewer($request), $id, $request));
    }

    private function reviewer(Request $request): Employee
    {
        $reviewer = $request->attributes->get('portalEmployee');
        if (! $reviewer instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $reviewer;
    }
}
