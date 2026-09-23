<?php

use App\Http\Controllers\Api\Portal\Home\DashboardController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-home')
        ->get('home', [DashboardController::class, 'show']);
});
