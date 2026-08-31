<?php

use App\Http\Controllers\Api\Portal\Calendar\HolidaysController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-calendar-holidays')
        ->get('calendar/holidays', [HolidaysController::class, 'index']);
});
