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
}
