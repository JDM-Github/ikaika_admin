<?php

use App\Http\Controllers\Api\Portal\User\HomeController;
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
    // Where the person lives, which they set after signing in and may change (Manage / Map reads it).
    Route::middleware('throttle:portal-user-notifications')->get('user/home', [HomeController::class, 'show']);
    // The address at their pin, for Profile's Use my pin (2026-10-19); one Nominatim read each.
    Route::middleware('throttle:portal-user-information-write')->get('user/home/address', [HomeController::class, 'address']);
    Route::middleware('throttle:portal-user-notifications-write')->put('user/home', [HomeController::class, 'update']);
    Route::middleware('throttle:portal-user-notifications-write')->group(function (): void {
        Route::patch('user/notifications/{id}/read', [NotificationsController::class, 'markRead'])
            ->whereNumber('id');
        Route::post('user/notifications/read-all', [NotificationsController::class, 'markAllRead']);
        Route::delete('user/notifications/{id}', [NotificationsController::class, 'destroy'])
            ->whereNumber('id');
    });
    // Their own employee information, which changes only once a head admin approves (2026-10-14).
    Route::middleware('throttle:portal-user-information')->group(function (): void {
        Route::get('user/information', [InformationController::class, 'show']);
        // The address pickers' lists, as the registration's (2026-10-19): provinces, a province's cities, a city's barangays.
        Route::get('user/places/provinces', [InformationController::class, 'provinces']);
        Route::get('user/places/provinces/{code}/cities', [InformationController::class, 'cities'])->where('code', '[0-9]{9}');
        Route::get('user/places/cities/{code}/barangays', [InformationController::class, 'barangays'])->where('code', '[0-9]{9}');
    });
    Route::middleware('throttle:portal-user-information-write')->group(function (): void {
        Route::post('user/information/changes', [InformationController::class, 'submit']);
        Route::delete('user/information/changes', [InformationController::class, 'withdraw']);
    });
});
