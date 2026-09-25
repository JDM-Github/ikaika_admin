<?php

use App\Http\Controllers\Api\Portal\Calendar\EventsController;
use App\Http\Controllers\Api\Portal\Calendar\HolidaysController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // Holidays stay open: the report forms and the reports calendar mark them too.
    Route::middleware('throttle:portal-calendar-holidays')
        ->get('calendar/holidays', [HolidaysController::class, 'index']);
    Route::middleware(['throttle:portal-calendar-events', 'portal.rule:calendar'])
        ->get('calendar/events', [EventsController::class, 'index']);
    // Adding an event, and the audience choices its form reads, follow Add events.
    Route::middleware(['throttle:portal-calendar-events-write', 'portal.rule:calendar.create'])
        ->post('calendar/events', [EventsController::class, 'store']);
    Route::middleware(['throttle:portal-calendar-event-options', 'portal.rule:calendar.create'])
        ->get('calendar/event-options', [EventsController::class, 'options']);
});
