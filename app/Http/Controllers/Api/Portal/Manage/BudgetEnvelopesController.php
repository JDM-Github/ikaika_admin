<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalBudgetPayors;
use App\Support\Portal\PortalBudgetSetup;
use App\Support\Portal\PortalBudgetTransactions;
use App\Support\Portal\PortalBudgetTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetEnvelopesController extends Controller
{
    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalBudgetSetup $setup,
        private readonly PortalBudgetTransfers $transfers,
        private readonly PortalBudgetTransactions $entries,
        private readonly PortalBudgetPayors $payors,
    ) {}

    // The payor list Add Transaction offers (2026-10-21): the ledger's names and the hand-kept ones.
    public function payors(): JsonResponse
    {
        return response()->json($this->payors->list());
    }

    public function storePayor(Request $request): JsonResponse
    {
        return response()->json($this->payors->add($this->actor($request), $request), 201);
    }

    public function removePayor(Request $request): JsonResponse
    {
        return response()->json($this->payors->remove($this->actor($request), $request));
    }

    public function index(): JsonResponse
    {
        return response()->json($this->envelopes->summary());
    }

    // The whole tracker ledger and the team balances, for the Budget Tracker page.
    public function transactions(Request $request): JsonResponse
    {
        return response()->json($this->envelopes->transactions($this->actor($request)));
    }

    // Income or an expense added from Finance / Transactions.
    public function storeTransaction(Request $request): JsonResponse
    {
        return response()->json($this->entries->add($this->actor($request), $request), 201);
    }

    public function updateTransaction(Request $request, int $id): JsonResponse
    {
        return response()->json($this->entries->edit($this->actor($request), $id, $request));
    }

    // The receipt photo an Add Transaction will name, uploaded ahead of it.
    public function storeTransactionReceipt(Request $request): JsonResponse
    {
        return response()->json($this->entries->storeReceipt($this->actor($request), $request), 201);
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

    // Money moved from one envelope to another, across their owners' accounts when those differ.
    public function storeTransfer(Request $request): JsonResponse
    {
        return response()->json($this->transfers->betweenEnvelopes($this->actor($request), $request), 201);
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
