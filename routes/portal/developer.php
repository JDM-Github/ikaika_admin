<?php

use App\Http\Controllers\Api\Portal\Developer\DeveloperModeController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

// Developers only: switch Developer mode on with a password (off needs none), and change it.
Route::middleware([AuthenticatePortalJwt::class, 'throttle:portal-developer'])->group(function (): void {
    Route::patch('developer/mode', [DeveloperModeController::class, 'updateMode']);
    Route::patch('developer/password', [DeveloperModeController::class, 'updatePassword']);
});
