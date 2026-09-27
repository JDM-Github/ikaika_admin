<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalBudgetEnvelopes;
use Illuminate\Http\JsonResponse;

class BudgetEnvelopesController extends Controller
{
    public function __construct(private readonly PortalBudgetEnvelopes $envelopes) {}

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
}
