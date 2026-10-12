<?php

use App\Http\Controllers\Api\Portal\Access\AccessController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

// Every signed-in person has an Access page: it lists only the powers they themselves hold.
Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-access')
        ->get('access', [AccessController::class, 'index']);
    Route::middleware('throttle:portal-access-write')
        ->patch('access/switches', [AccessController::class, 'updateSwitch']);
    Route::middleware('throttle:portal-access-write')
        ->patch('access/reach', [AccessController::class, 'updateReach']);
});
