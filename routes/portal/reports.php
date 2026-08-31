<?php

use App\Http\Controllers\Api\Portal\Reports\SubmittedController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-reports-submitted')
        ->get('reports/submitted', [SubmittedController::class, 'index']);

    Route::middleware('throttle:portal-reports-submitted-write')->group(function (): void {
        Route::patch('reports/submitted/{id}', [SubmittedController::class, 'update'])
            ->where('id', '[0-9]{4}-[0-9]{2}-[0-9]{2}-(daily|late)');
        Route::delete('reports/submitted/{id}', [SubmittedController::class, 'destroy'])
            ->where('id', '[0-9]{4}-[0-9]{2}-[0-9]{2}-(daily|late)');
    });
});
