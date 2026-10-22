<?php

namespace App\Http\Controllers\Api\Portal\ClashFlow;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalClashFlowWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClashFlowWorkflowController extends Controller
{
    public function __construct(private readonly PortalClashFlowWorkflow $workflow) {}

    public function show(Request $request, int $project): JsonResponse
    {
        return response()->json($this->workflow->snapshot($this->actor($request), $project));
    }

    public function update(Request $request, int $project): JsonResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array', 'max:2000'],
            'records.*.type' => ['required', 'string', Rule::in(['project', 'group', 'batch'])],
            'records.*.key' => ['required', 'string', 'max:100'],
            'records.*.payload' => ['required', 'string'],
            'records.*.version' => ['required', 'integer', 'min:0'],
        ]);

        $result = $this->workflow->apply($this->actor($request), $project, $validated['records']);

        return response()->json($result, $result['conflicts'] === [] ? 200 : 409);
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
