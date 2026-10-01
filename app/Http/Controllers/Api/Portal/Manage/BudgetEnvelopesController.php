<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalBudgetSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetEnvelopesController extends Controller
{
    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalBudgetSetup $setup,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->envelopes->summary());
    }

    // The whole tracker ledger and the team balances, for the Budget Tracker page.
    public function transactions(): JsonResponse
    {
        return response()->json($this->envelopes->transactions());
    }

    // Every envelope under its budget code, for the Budget Tracker's Envelopes tab.
    public function codes(): JsonResponse
    {
        return response()->json($this->envelopes->budgetCodes());
    }

    // A new budget code, from Finance / Envelopes.
    public function storeCode(Request $request): JsonResponse
    {
        return response()->json($this->setup->createCode($this->actor($request), $request), 201);
    }

    // A new envelope, under a budget code or none, from Finance / Envelopes.
    public function storeEnvelope(Request $request): JsonResponse
    {
        return response()->json($this->setup->createEnvelope($this->actor($request), $request), 201);
    }

    public function updateCode(Request $request, int $id): JsonResponse
    {
        return response()->json($this->setup->updateCode($this->actor($request), $id, $request));
    }

    public function updateEnvelope(Request $request, int $id): JsonResponse
    {
        return response()->json($this->setup->updateEnvelope($this->actor($request), $id, $request));
    }

    // Money received into an envelope, recorded as the tracker's own Funds Received rows are.
    public function storeFunds(Request $request, int $id): JsonResponse
    {
        return response()->json($this->setup->recordFunds($this->actor($request), $id, $request), 201);
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
