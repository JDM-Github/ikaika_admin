<?php

use App\Http\Controllers\Api\Portal\Requests\LeaveController;
use App\Http\Controllers\Api\Portal\Requests\OffsetController;
use App\Http\Controllers\Api\Portal\Requests\OvertimeController;
use App\Http\Controllers\Api\Portal\Requests\ReimbursementController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // Each own list is read by User Requests and by its filing form (and the Edit pages, under User Requests).
    Route::middleware('throttle:portal-requests')->group(function (): void {
        Route::get('requests/leave', [LeaveController::class, 'index'])->middleware('portal.rule:requests.own|requests.leave');
        Route::get('requests/overtime', [OvertimeController::class, 'index'])->middleware('portal.rule:requests.own|requests.overtime');
        Route::get('requests/offset', [OffsetController::class, 'index'])->middleware('portal.rule:requests.own|requests.offset');
        Route::get('requests/reimbursement', [ReimbursementController::class, 'index'])->middleware('portal.rule:requests.own|requests.reimbursement');
    });

    // Filing follows its form's rule; changing and withdrawing follow User Requests' Edit and Cancel.
    Route::middleware('throttle:portal-requests-write')->group(function (): void {
        // Each write is the filer's own work, so Developer mode past Default waits for confirmation (portal.own).
        Route::post('requests/leave', [LeaveController::class, 'store'])->middleware('portal.own:requests.leave');
        Route::patch('requests/leave/{id}', [LeaveController::class, 'update'])
            ->middleware('portal.own:requests.own.edit')
            ->where('id', '[0-9]+');
        Route::post('requests/leave/{id}/cancel', [LeaveController::class, 'cancel'])
            ->middleware('portal.own:requests.own.cancel')
            ->where('id', '[0-9]+');
        Route::post('requests/overtime', [OvertimeController::class, 'store'])->middleware('portal.own:requests.overtime');
        Route::patch('requests/overtime/{id}', [OvertimeController::class, 'update'])
            ->middleware('portal.own:requests.own.edit')
            ->where('id', '[0-9]+');
        Route::post('requests/overtime/{id}/cancel', [OvertimeController::class, 'cancel'])
            ->middleware('portal.own:requests.own.cancel')
            ->where('id', '[0-9]+');
        Route::post('requests/offset', [OffsetController::class, 'store'])->middleware('portal.own:requests.offset');
        Route::patch('requests/offset/{id}', [OffsetController::class, 'update'])
            ->middleware('portal.own:requests.own.edit')
            ->where('id', '[0-9]+');
        Route::post('requests/offset/{id}/cancel', [OffsetController::class, 'cancel'])
            ->middleware('portal.own:requests.own.cancel')
            ->where('id', '[0-9]+');
        Route::post('requests/reimbursement', [ReimbursementController::class, 'store'])->middleware('portal.own:requests.reimbursement');
        // Receipts are uploaded while filing a claim or while editing one.
        Route::post('requests/reimbursement/receipts', [ReimbursementController::class, 'storeReceipt'])
            ->middleware('portal.rule:requests.reimbursement|requests.own.edit');
        Route::patch('requests/reimbursement/{id}', [ReimbursementController::class, 'update'])
            ->middleware('portal.own:requests.own.edit')
            ->where('id', '[0-9]+');
        Route::post('requests/reimbursement/{id}/cancel', [ReimbursementController::class, 'cancel'])
            ->middleware('portal.own:requests.own.cancel')
            ->where('id', '[0-9]+');
    });
});
