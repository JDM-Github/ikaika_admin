<?php

use App\Http\Controllers\Api\Portal\User\LogsController;
use App\Http\Controllers\Api\Portal\User\NotificationsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-user-logs')->group(function (): void {
        Route::get('user/logs', [LogsController::class, 'index']);
        Route::get('user/logs/{id}', [LogsController::class, 'show'])->whereNumber('id');
    });
    Route::middleware('throttle:portal-user-notifications')
        ->get('user/notifications', [NotificationsController::class, 'index']);
    Route::middleware('throttle:portal-user-notifications-write')->group(function (): void {
        Route::patch('user/notifications/{id}/read', [NotificationsController::class, 'markRead'])
            ->whereNumber('id');
        Route::post('user/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    });
});
