<?php

use App\Http\Controllers\Api\Portal\Reports\FlaggedController;
use App\Http\Controllers\Api\Portal\Reports\ProjectsController;
use App\Http\Controllers\Api\Portal\Reports\SubmittedController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-reports-projects')
        ->get('reports/projects', [ProjectsController::class, 'index']);

    Route::middleware('throttle:portal-reports-submitted')->group(function (): void {
        // Before the list route, so /days is not read as a submitted-report id.
        Route::get('reports/submitted/days', [SubmittedController::class, 'days']);
        Route::get('reports/submitted', [SubmittedController::class, 'index']);
        // Same data source (PortalSubmittedReports), same throttle -- a filtered read, not a
        // separate resource.
        Route::get('reports/flagged', [FlaggedController::class, 'index']);
    });

    Route::middleware('throttle:portal-reports-submitted-write')->group(function (): void {
        Route::post('reports/submitted', [SubmittedController::class, 'store']);
        Route::patch('reports/submitted/{id}', [SubmittedController::class, 'update'])
            ->where('id', '[0-9]{4}-[0-9]{2}-[0-9]{2}-(daily|late)');
        Route::delete('reports/submitted/{id}', [SubmittedController::class, 'destroy'])
            ->where('id', '[0-9]{4}-[0-9]{2}-[0-9]{2}-(daily|late)');
    });
});
