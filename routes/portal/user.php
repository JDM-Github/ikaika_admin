<?php

use App\Http\Controllers\Api\Portal\User\HomeController;
use App\Http\Controllers\Api\Portal\User\LogsController;
use App\Http\Controllers\Api\Portal\User\NotificationsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware(['throttle:portal-user-logs', 'portal.rule:user.logs'])->group(function (): void {
        Route::get('user/logs', [LogsController::class, 'index']);
        Route::get('user/logs/{id}', [LogsController::class, 'show'])->whereNumber('id');
    });
    Route::middleware('throttle:portal-user-notifications')->group(function (): void {
        Route::get('user/notifications', [NotificationsController::class, 'index']);
        Route::get('user/notifications/unread', [NotificationsController::class, 'unread']);
    });
    // Where the person lives, which they set after signing in and may change (Manage / Map reads it).
    Route::middleware('throttle:portal-user-notifications')->get('user/home', [HomeController::class, 'show']);
    Route::middleware('throttle:portal-user-notifications-write')->put('user/home', [HomeController::class, 'update']);
    Route::middleware('throttle:portal-user-notifications-write')->group(function (): void {
        Route::patch('user/notifications/{id}/read', [NotificationsController::class, 'markRead'])
            ->whereNumber('id');
        Route::post('user/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    });
});
