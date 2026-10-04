<?php

use App\Http\Controllers\Api\Portal\Signature\SignatureController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // The signed-in employee's own email signature, on Settings. Any member reads their own.
    Route::get('signature', [SignatureController::class, 'show']);
});
