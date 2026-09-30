<?php

use App\Http\Controllers\Api\Portal\Administration\AllActionsController;
use App\Http\Controllers\Api\Portal\Administration\AllLogsController;
use App\Http\Controllers\Api\Portal\Administration\EmailBlocksController;
use App\Http\Controllers\Api\Portal\Administration\EmailImagesController;
use App\Http\Controllers\Api\Portal\Administration\EmailMessagesController;
use App\Http\Controllers\Api\Portal\Administration\MissingReportsController;
use App\Http\Controllers\Api\Portal\Administration\RecycleBinController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // All Logs follows its rule and reach; who else's rows show is decided per row in PortalUserLogs.
    Route::middleware(['portal.rule:administration.logs', 'throttle:portal-administration-all-logs'])->group(function (): void {
        Route::get('administration/all-logs', [AllLogsController::class, 'index']);
        Route::get('administration/all-logs/{id}', [AllLogsController::class, 'show'])->whereNumber('id');
    });

    // All Actions follows its rule and reach like All Logs; whose actions show is decided in PortalAllActions.
    Route::middleware(['portal.rule:administration.actions', 'throttle:portal-administration-all-actions'])->group(function (): void {
        Route::get('administration/all-actions', [AllActionsController::class, 'index']);
        Route::get('administration/all-actions/{id}', [AllActionsController::class, 'show'])->whereNumber('id');
    });
    // Missing Reports follows its rule and reach: only the people the reach covers are listed or reminded.
    Route::middleware(['portal.rule:administration.missing', 'throttle:portal-manage-reports'])
        ->get('administration/missing-reports', [MissingReportsController::class, 'index']);
    Route::middleware(['portal.rule:administration.missing.email', 'throttle:portal-administration-email-send'])
        ->post('administration/missing-reports/reminders', [MissingReportsController::class, 'remind']);

    Route::middleware('throttle:portal-administration-recycle-bin')->group(function (): void {
        Route::get('administration/recycle-bin', [RecycleBinController::class, 'index']);
        Route::get('administration/recycle-bin/{id}', [RecycleBinController::class, 'show'])
            ->whereNumber('id');
    });

    Route::middleware('throttle:portal-administration-recycle-bin-write')
        ->post('administration/recycle-bin/{id}/restore', [RecycleBinController::class, 'restore'])
        ->whereNumber('id');

    // Email (the composer) follows its rule: whoever opens it reads the audiences, templates, footers and
    // images, builds previews and keeps their own images. Sending and the library are powers of their own.
    Route::middleware(['portal.rule:email', 'throttle:portal-administration-email'])->group(function (): void {
        // Before the {id} route so the fixed path is never read as an id.
        Route::get('administration/emails/audiences', [EmailMessagesController::class, 'audiences']);
        // Read-only despite the POST: the draft rides in the body, too long for a query string.
        Route::post('administration/emails/preview', [EmailMessagesController::class, 'preview']);
        Route::get('administration/email-blocks', [EmailBlocksController::class, 'index']);
        Route::get('administration/email-images', [EmailImagesController::class, 'index']);
    });

    // Administration / All Emails: the sent list and one sent email.
    Route::middleware(['portal.rule:administration.emails', 'throttle:portal-administration-email'])->group(function (): void {
        Route::get('administration/emails', [EmailMessagesController::class, 'index']);
        Route::get('administration/emails/{id}', [EmailMessagesController::class, 'show'])
            ->whereNumber('id');
    });

    Route::middleware(['portal.rule:email.library', 'throttle:portal-administration-email-write'])->group(function (): void {
        Route::post('administration/email-blocks', [EmailBlocksController::class, 'store']);
        Route::patch('administration/email-blocks/{id}', [EmailBlocksController::class, 'update'])
            ->whereNumber('id');
        Route::delete('administration/email-blocks/{id}', [EmailBlocksController::class, 'destroy'])
            ->whereNumber('id');
    });

    Route::middleware(['portal.rule:email', 'throttle:portal-administration-email-write'])->group(function (): void {
        Route::post('administration/email-images', [EmailImagesController::class, 'store']);
        Route::delete('administration/email-images/{id}', [EmailImagesController::class, 'destroy']);
    });

    Route::middleware(['portal.rule:email.send', 'throttle:portal-administration-email-send'])
        ->post('administration/emails', [EmailMessagesController::class, 'store']);
});
