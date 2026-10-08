<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalMissingReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissingReportsController extends Controller
{
    public function __construct(private readonly PortalMissingReports $missing) {}

    // The people the reader's reach covers, with their filed days, for the screen that works out who has not reported.
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->missing->list($this->actor($request), $request));
    }

    public function remind(Request $request): JsonResponse
    {
        return response()->json($this->missing->remind($this->actor($request), $request), 201);
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
