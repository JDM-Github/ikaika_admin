<?php

use App\Http\Controllers\Api\Portal\Calendar\EventsController;
use App\Http\Controllers\Api\Portal\Calendar\HolidaysController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-calendar-holidays')
        ->get('calendar/holidays', [HolidaysController::class, 'index']);
    Route::middleware('throttle:portal-calendar-events')
        ->get('calendar/events', [EventsController::class, 'index']);
    Route::middleware('throttle:portal-calendar-events-write')
        ->post('calendar/events', [EventsController::class, 'store']);
    Route::middleware('throttle:portal-calendar-event-options')
        ->get('calendar/event-options', [EventsController::class, 'options']);
});
