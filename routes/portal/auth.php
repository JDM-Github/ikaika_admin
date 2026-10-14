<?php

use App\Http\Controllers\Api\Portal\Auth\LoginMethodsController;
use App\Http\Controllers\Api\PortalAuthController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:portal-auth')->group(function (): void {
    // Public: what the login screen may offer, read before any session exists.
    Route::get('auth/id-login', [LoginMethodsController::class, 'show']);
    Route::post('auth/login', [PortalAuthController::class, 'login']);
    Route::post('auth/login/microsoft', [PortalAuthController::class, 'loginMicrosoft']);
    Route::post('auth/email/start', [PortalAuthController::class, 'emailStart']);
    Route::post('auth/email/verify', [PortalAuthController::class, 'emailVerify']);
});
Route::get('auth/me', [PortalAuthController::class, 'me'])
    ->middleware(AuthenticatePortalJwt::class);
