<?php

use App\Http\Controllers\Api\Portal\Manage\AccessController;
use App\Http\Controllers\Api\Portal\Manage\BudgetEnvelopesController;
use App\Http\Controllers\Api\Portal\Manage\ReportsController;
use App\Http\Controllers\Api\Portal\Manage\RequestsController;
use App\Http\Controllers\Api\Portal\Manage\UsersController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // The roster and profiles follow Users and its reach; the private block inside a profile follows Show employee information.
    Route::middleware(['portal.rule:manage.users', 'throttle:portal-manage-users'])
        ->get('manage/users', [UsersController::class, 'index']);
    Route::middleware(['portal.rule:manage.users', 'throttle:portal-manage-users'])
        ->get('manage/users/{id}', [UsersController::class, 'show'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.users.role', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/role', [UsersController::class, 'updateRole'])
        ->whereNumber('id');
    // Each kind on the queue and its Approve or reject follow their own rule inside PortalManageRequests.
    Route::middleware(['portal.rule:manage.requests', 'throttle:portal-manage-requests'])
        ->get('manage/requests', [RequestsController::class, 'index']);
    // The envelope picker an approval charges: whoever may approve a reimbursement claim reads it.
    Route::middleware(['portal.rule:manage.requests.reimbursement.decide', 'throttle:portal-manage-requests'])
        ->get('manage/budget-envelopes', [BudgetEnvelopesController::class, 'index']);
    // The ledger and the budget codes feed all three Finance pages, so any of their rules reads them.
    Route::middleware(['portal.rule:finance.overview|finance.transactions|finance.envelopes', 'throttle:portal-manage-requests'])
        ->get('manage/budget-transactions', [BudgetEnvelopesController::class, 'transactions']);
    Route::middleware(['portal.rule:finance.overview|finance.transactions|finance.envelopes', 'throttle:portal-manage-requests'])
        ->get('manage/budget-codes', [BudgetEnvelopesController::class, 'codes']);
    Route::middleware(['portal.rule:manage.requests', 'throttle:portal-manage-requests-write'])
        ->patch('manage/requests', [RequestsController::class, 'update']);
    Route::middleware(['portal.rule:manage.requests.reimbursement.payout', 'throttle:portal-manage-requests-write'])
        ->post('manage/requests/reimbursement/{id}/payout-receipt', [RequestsController::class, 'storePayoutReceipt'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.reports', 'throttle:portal-manage-reports'])
        ->get('manage/reports', [ReportsController::class, 'index']);
    Route::middleware(['portal.rule:manage.users.access', 'throttle:portal-manage-access'])
        ->get('manage/access/{id}', [AccessController::class, 'show'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.users.access', 'throttle:portal-manage-access-write'])
        ->patch('manage/access/{id}', [AccessController::class, 'update'])
        ->whereNumber('id');
});
