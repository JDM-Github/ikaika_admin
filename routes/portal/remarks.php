<?php

use App\Http\Controllers\Api\Portal\Remarks\RemarksController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

/*
 * The "Polish" tidy-up, one endpoint for every remarks box in the portal -- a report's remarks, a
 * leave or reimbursement justification. It files nothing: it only rewrites what the box already holds,
 * so it needs no rule beyond being signed in.
 */
Route::middleware([AuthenticatePortalJwt::class, 'throttle:portal-report-remark'])->group(function (): void {
    Route::post('remarks/format', [RemarksController::class, 'format']);
    // The same tidy-up for a box holding several notes at once (the approver's review rows).
    Route::post('remarks/format-many', [RemarksController::class, 'formatMany']);
});
