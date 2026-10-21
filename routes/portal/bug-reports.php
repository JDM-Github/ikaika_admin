<?php

use App\Http\Controllers\Api\Portal\BugReports\BugReportsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // Anyone signed in reports a bug, anonymously; the portal sends no location with it.
    Route::middleware('throttle:portal-bug-report')
        ->post('bug-reports', [BugReportsController::class, 'store']);
    // MIS reads and tracks them.
    Route::middleware('portal.rule:bug-reports')
        ->get('bug-reports', [BugReportsController::class, 'index']);
    Route::middleware(['portal.rule:bug-reports', 'throttle:portal-bug-report-write'])
        ->patch('bug-reports/{id}', [BugReportsController::class, 'update'])
        ->whereNumber('id');
    // Archive and delete (2026-10-21, user): archiving comes back, so it is the page's; deleting is a power of its
    // own, and its throttle is the Recycle Bin's, where the report lands.
    Route::middleware(['portal.rule:bug-reports', 'throttle:portal-bug-report-write'])
        ->patch('bug-reports/{id}/archive', [BugReportsController::class, 'archive'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:bug-reports.delete', 'throttle:portal-administration-recycle-bin-write'])
        ->delete('bug-reports/{id}', [BugReportsController::class, 'destroy'])
        ->whereNumber('id');
    // Refresh: every linked one read back from Jira now, then the list. It only reads, so it is the viewer's.
    Route::middleware(['portal.rule:bug-reports', 'throttle:portal-bug-report-write'])
        ->post('bug-reports/jira/sync', [BugReportsController::class, 'refreshFromJira']);
    // Jira (2026-10-19): send one by hand or try it again, and the switch that sends new ones on their own.
    Route::middleware(['portal.rule:bug-reports.jira', 'throttle:portal-bug-report-write'])
        ->post('bug-reports/{id}/jira', [BugReportsController::class, 'sendToJira'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:bug-reports.jira', 'throttle:portal-bug-report-write'])
        ->patch('bug-reports/jira', [BugReportsController::class, 'updateJira']);
    // Every report not in Jira yet, at once (2026-10-20): production had reports from before Jira.
    Route::middleware(['portal.rule:bug-reports.jira', 'throttle:portal-bug-report-write'])
        ->post('bug-reports/jira/send-all', [BugReportsController::class, 'sendAllToJira']);
});
