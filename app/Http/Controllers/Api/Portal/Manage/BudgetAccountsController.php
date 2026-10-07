<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalBudgetAccounts;
use App\Support\Portal\PortalBudgetTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetAccountsController extends Controller
{
    public function __construct(
        private readonly PortalBudgetAccounts $accounts,
        private readonly PortalBudgetTransfers $transfers,
    ) {}

    // Every account with its balance, and the fund transfers between accounts, for Finance / Accounts.
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->accounts->list($this->actor($request)));
    }

    public function storeFunds(Request $request, int $id): JsonResponse
    {
        return response()->json($this->accounts->addFunds($this->actor($request), $id, $request), 201);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        return response()->json($this->transfers->betweenAccounts($this->actor($request), $request), 201);
    }

    public function completeTransfer(Request $request, int $id): JsonResponse
    {
        return response()->json($this->transfers->complete($this->actor($request), $id, $request));
    }

    public function cancelTransfer(Request $request, int $id): JsonResponse
    {
        return response()->json($this->transfers->cancel($this->actor($request), $id, $request));
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
