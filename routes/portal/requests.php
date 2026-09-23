<?php

use App\Http\Controllers\Api\Portal\Requests\LeaveController;
use App\Http\Controllers\Api\Portal\Requests\OffsetController;
use App\Http\Controllers\Api\Portal\Requests\OvertimeController;
use App\Http\Controllers\Api\Portal\Requests\ReimbursementController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-requests')->group(function (): void {
        Route::get('requests/leave', [LeaveController::class, 'index']);
        Route::get('requests/overtime', [OvertimeController::class, 'index']);
        Route::get('requests/offset', [OffsetController::class, 'index']);
        Route::get('requests/reimbursement', [ReimbursementController::class, 'index']);
    });

    Route::middleware('throttle:portal-requests-write')->group(function (): void {
        Route::post('requests/leave', [LeaveController::class, 'store']);
        Route::patch('requests/leave/{id}', [LeaveController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::post('requests/leave/{id}/cancel', [LeaveController::class, 'cancel'])
            ->where('id', '[0-9]+');
        Route::post('requests/overtime', [OvertimeController::class, 'store']);
        Route::patch('requests/overtime/{id}', [OvertimeController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::post('requests/overtime/{id}/cancel', [OvertimeController::class, 'cancel'])
            ->where('id', '[0-9]+');
        Route::post('requests/offset', [OffsetController::class, 'store']);
        Route::patch('requests/offset/{id}', [OffsetController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::post('requests/offset/{id}/cancel', [OffsetController::class, 'cancel'])
            ->where('id', '[0-9]+');
        Route::post('requests/reimbursement', [ReimbursementController::class, 'store']);
        Route::post('requests/reimbursement/receipts', [ReimbursementController::class, 'storeReceipt']);
        Route::patch('requests/reimbursement/{id}', [ReimbursementController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::post('requests/reimbursement/{id}/cancel', [ReimbursementController::class, 'cancel'])
            ->where('id', '[0-9]+');
    });
});
