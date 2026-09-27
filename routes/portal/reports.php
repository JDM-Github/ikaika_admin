<?php

use App\Http\Controllers\Api\Portal\Reports\FlaggedController;
use App\Http\Controllers\Api\Portal\Reports\ProjectsController;
use App\Http\Controllers\Api\Portal\Reports\SubmittedController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // The filing forms read these: Daily, Late and Edit each need one of their rules.
    Route::middleware(['throttle:portal-reports-projects', 'portal.rule:reports.daily|reports.late|reports.submitted.edit'])
        ->get('reports/projects', [ProjectsController::class, 'index']);

    Route::middleware('throttle:portal-reports-submitted')->group(function (): void {
        // Before the list route, so /days is not read as a submitted-report id. The filing forms read
        // both too, to block a day already filed and to open a report for editing.
        Route::middleware('portal.rule:reports.daily|reports.late|reports.submitted')->group(function (): void {
            Route::get('reports/submitted/days', [SubmittedController::class, 'days']);
            Route::get('reports/submitted', [SubmittedController::class, 'index']);
        });
        // Same data source (PortalSubmittedReports), same throttle -- a filtered read, not a
        // separate resource.
        Route::get('reports/flagged', [FlaggedController::class, 'index']);
    });

    Route::middleware('throttle:portal-reports-submitted-write')->group(function (): void {
        // Filing checks Daily or Late by the kind it is sent, once the kind is read. Each is the
        // person's own work, so Developer mode past Default waits for confirmation (portal.own).
        Route::post('reports/submitted', [SubmittedController::class, 'store']);
        Route::patch('reports/submitted/{id}', [SubmittedController::class, 'update'])
            ->middleware('portal.own:reports.submitted.edit')
            ->where('id', '[0-9]{4}-[0-9]{2}-[0-9]{2}-(daily|late)');
        Route::delete('reports/submitted/{id}', [SubmittedController::class, 'destroy'])
            ->middleware('portal.own:reports.submitted.delete')
            ->where('id', '[0-9]{4}-[0-9]{2}-[0-9]{2}-(daily|late)');
    });
});
