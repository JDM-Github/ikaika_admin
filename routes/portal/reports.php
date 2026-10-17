<?php

use App\Http\Controllers\Api\Portal\Reports\ProjectsController;
use App\Http\Controllers\Api\Portal\Reports\ReportTemplatesController;
use App\Http\Controllers\Api\Portal\Reports\SubmittedController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // The filing forms read these: Daily, Late and Edit each need one of their rules.
    Route::middleware(['throttle:portal-reports-projects', 'portal.rule:reports.daily|reports.late|reports.submitted.edit'])
        ->get('reports/projects', [ProjectsController::class, 'index']);
    // An MIS project's commits on the report's day, from its linked Bitbucket repositories.
    Route::middleware(['throttle:portal-reports-projects', 'portal.rule:reports.daily|reports.late|reports.submitted.edit'])
        ->get('reports/code-activity', [ProjectsController::class, 'codeActivity']);

    // The Use a Template panel: each person's own saved recipes, read and written only by them.
    Route::middleware(['throttle:portal-reports-submitted', 'portal.rule:reports.daily|reports.late|requests.overtime|requests.offset'])
        ->get('reports/templates', [ReportTemplatesController::class, 'index']);
    Route::middleware(['throttle:portal-reports-submitted-write', 'portal.rule:reports.daily|reports.late|requests.overtime|requests.offset'])
        ->group(function (): void {
            Route::post('reports/templates', [ReportTemplatesController::class, 'store']);
            Route::patch('reports/templates/{id}', [ReportTemplatesController::class, 'update'])->where('id', '[0-9]+');
            Route::delete('reports/templates/{id}', [ReportTemplatesController::class, 'destroy'])->where('id', '[0-9]+');
        });

    Route::middleware('throttle:portal-reports-submitted')->group(function (): void {
        // Before the list route, so /days is not read as a submitted-report id. The filing forms read
        // both too, to block a day already filed and to open a report for editing.
        Route::middleware('portal.rule:reports.daily|reports.late|reports.submitted')->group(function (): void {
            Route::get('reports/submitted/days', [SubmittedController::class, 'days']);
            Route::get('reports/submitted', [SubmittedController::class, 'index']);
        });
        // User Requests' Late tab: their own late reports, read by that page or the Late Report form.
        Route::get('reports/late', [SubmittedController::class, 'late'])->middleware('portal.rule:requests.own|reports.late');
        // User Requests' Daily Reports tab: their own daily reports that waited for review (2026-10-07).
        // A member now sees where each stands, so a report held by a warning shows as waiting too.
        Route::get('reports/reviews', [SubmittedController::class, 'reviews'])->middleware('portal.rule:requests.own');
    });

    // The Report remarks card's Polish button, for the forms that carry Report remarks. It files
    // nothing -- it only tidies the note in the box -- but it reaches a model, so it has a throttle of its own.
    Route::middleware(['throttle:portal-report-remark', 'portal.rule:reports.daily|reports.late|reports.submitted.edit'])
        ->post('reports/remarks/format', [SubmittedController::class, 'formatRemarks']);

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
