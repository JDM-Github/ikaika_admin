<?php

namespace App\Http\Controllers\Api\Portal\Access;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function __construct(private readonly PortalAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->access->powers($this->actor($request), $request));
    }

    public function updateSwitch(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'on' => ['required', 'boolean'],
        ]);

        return response()->json($this->access->setSwitch($actor, $validated['key'], (bool) $validated['on'], $request));
    }

    public function updateReach(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'reach' => ['required', 'string', 'in:own,department,all'],
        ]);

        return response()->json($this->access->setReach($actor, $validated['key'], $validated['reach'], $request));
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
