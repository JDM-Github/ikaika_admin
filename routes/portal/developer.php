<?php

use App\Http\Controllers\Api\Portal\Developer\DeveloperModeController;
use App\Http\Controllers\Api\Portal\Settings\AdvancedSettingsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

// Developers only: switch Developer mode on with a password (off needs none), and change it.
Route::middleware([AuthenticatePortalJwt::class, 'throttle:portal-developer'])->group(function (): void {
    Route::patch('developer/mode', [DeveloperModeController::class, 'updateMode']);
    Route::patch('developer/password', [DeveloperModeController::class, 'updatePassword']);
});

// Settings' Advanced card, read and written only while Developer mode is on. It does not check
// the Developer mode password, so it has its own ordinary throttle, not the password bucket.
Route::middleware([AuthenticatePortalJwt::class, 'throttle:portal-developer-settings'])->group(function (): void {
    Route::get('settings/advanced', [AdvancedSettingsController::class, 'show']);
    Route::patch('settings/advanced', [AdvancedSettingsController::class, 'update']);
});
