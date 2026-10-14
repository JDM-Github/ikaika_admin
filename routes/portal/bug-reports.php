<?php

use App\Http\Controllers\Api\Portal\BugReports\BugReportsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // Anyone signed in reports a bug, anonymously; RequirePortalLocation exempts this one write.
    Route::middleware('throttle:portal-bug-report')
        ->post('bug-reports', [BugReportsController::class, 'store']);
    // MIS reads and tracks them.
    Route::middleware('portal.rule:bug-reports')
        ->get('bug-reports', [BugReportsController::class, 'index']);
    Route::middleware(['portal.rule:bug-reports', 'throttle:portal-bug-report-write'])
        ->patch('bug-reports/{id}', [BugReportsController::class, 'update'])
        ->whereNumber('id');
});
