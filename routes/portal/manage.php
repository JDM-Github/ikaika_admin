<?php

use App\Http\Controllers\Api\Portal\Manage\BudgetEnvelopesController;
use App\Http\Controllers\Api\Portal\Manage\ReportsController;
use App\Http\Controllers\Api\Portal\Manage\RequestsController;
use App\Http\Controllers\Api\Portal\Manage\UsersController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware(['portal.admin', 'throttle:portal-manage-users'])
        ->get('manage/users', [UsersController::class, 'index']);
    Route::middleware(['portal.admin', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/role', [UsersController::class, 'updateRole'])
        ->whereNumber('id');
    Route::middleware(['portal.admin', 'throttle:portal-manage-requests'])
        ->get('manage/requests', [RequestsController::class, 'index']);
    // Read by the reimbursement queue, so it shares that queue's admin gate and limiter.
    Route::middleware(['portal.admin', 'throttle:portal-manage-requests'])
        ->get('manage/budget-envelopes', [BudgetEnvelopesController::class, 'index']);
    Route::middleware(['portal.admin', 'throttle:portal-manage-requests'])
        ->get('manage/budget-transactions', [BudgetEnvelopesController::class, 'transactions']);
    Route::middleware(['portal.admin', 'throttle:portal-manage-requests-write'])
        ->patch('manage/requests', [RequestsController::class, 'update']);
    Route::middleware(['portal.admin', 'throttle:portal-manage-reports'])
        ->get('manage/reports', [ReportsController::class, 'index']);
});
