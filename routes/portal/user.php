<?php

use App\Http\Controllers\Api\Portal\User\InformationController;
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
    Route::middleware('throttle:portal-user-notifications-write')->group(function (): void {
        Route::patch('user/notifications/{id}/read', [NotificationsController::class, 'markRead'])
            ->whereNumber('id');
        Route::post('user/notifications/read-all', [NotificationsController::class, 'markAllRead']);
        Route::delete('user/notifications/{id}', [NotificationsController::class, 'destroy'])
            ->whereNumber('id');
    });
    // Their own employee information, which changes only once a head admin approves (2026-10-14).
    Route::middleware('throttle:portal-user-information')
        ->get('user/information', [InformationController::class, 'show']);
    Route::middleware('throttle:portal-user-information-write')->group(function (): void {
        Route::post('user/information/changes', [InformationController::class, 'submit']);
        Route::delete('user/information/changes', [InformationController::class, 'withdraw']);
    });
});
