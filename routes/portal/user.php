<?php

use App\Http\Controllers\Api\Portal\User\LogsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-user-logs')
        ->get('user/logs', [LogsController::class, 'index']);
});
