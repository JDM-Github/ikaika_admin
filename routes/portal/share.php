<?php

use App\Http\Controllers\Api\Portal\ShareController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

/*
 * Shared pages, the public side. The code in the path names the share and the password (plus the
 * mailed code when an email is bound) is what signs the guest in, so no auth middleware sits on the
 * sign-in routes. A live guest session is confirmed through share/session with its own token.
 */
Route::get('share/session', [ShareController::class, 'session'])
    ->middleware([AuthenticatePortalJwt::class, 'throttle:portal-share']);

Route::prefix('share/{code}')->middleware('throttle:portal-share-auth')->group(function (): void {
    Route::get('', [ShareController::class, 'info']);
    Route::post('access', [ShareController::class, 'access']);
    Route::post('verify', [ShareController::class, 'verify']);
    Route::post('resend', [ShareController::class, 'resend']);
});
