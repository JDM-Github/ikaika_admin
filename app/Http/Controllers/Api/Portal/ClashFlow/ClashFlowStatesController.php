<?php

namespace App\Http\Controllers\Api\Portal\ClashFlow;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalClashFlowStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClashFlowStatesController extends Controller
{
    public function __construct(private readonly PortalClashFlowStates $states) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => (object) $this->states->mine($this->actor($request))]);
    }

    public function update(Request $request, string $key): Response
    {
        // The state is JSON text, kept as sent: ClashFlow owns its shape.
        $validated = $request->validate(['state' => ['required', 'string']]);
        $this->states->save($this->actor($request), $key, $validated['state']);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $this->states->reset($this->actor($request));

        return response()->noContent();
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
