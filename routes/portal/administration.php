<?php

use App\Http\Controllers\Api\Portal\Administration\AllLogsController;
use App\Http\Controllers\Api\Portal\Administration\EmailBlocksController;
use App\Http\Controllers\Api\Portal\Administration\EmailMessagesController;
use App\Http\Controllers\Api\Portal\Administration\RecycleBinController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware(['portal.admin', 'throttle:portal-administration-all-logs'])
        ->get('administration/all-logs', [AllLogsController::class, 'index']);

    Route::middleware('throttle:portal-administration-recycle-bin')->group(function (): void {
        Route::get('administration/recycle-bin', [RecycleBinController::class, 'index']);
        Route::get('administration/recycle-bin/{id}', [RecycleBinController::class, 'show'])
            ->whereNumber('id');
    });

    Route::middleware('throttle:portal-administration-recycle-bin-write')
        ->post('administration/recycle-bin/{id}/restore', [RecycleBinController::class, 'restore'])
        ->whereNumber('id');

    Route::middleware(['portal.admin', 'throttle:portal-administration-email'])->group(function (): void {
        Route::get('administration/emails', [EmailMessagesController::class, 'index']);
        // Before the {id} route so the fixed path is never read as an id.
        Route::get('administration/emails/audiences', [EmailMessagesController::class, 'audiences']);
        Route::get('administration/emails/{id}', [EmailMessagesController::class, 'show'])
            ->whereNumber('id');
        Route::get('administration/email-blocks', [EmailBlocksController::class, 'index']);
    });

    Route::middleware(['portal.admin', 'throttle:portal-administration-email-write'])->group(function (): void {
        Route::post('administration/email-blocks', [EmailBlocksController::class, 'store']);
        Route::patch('administration/email-blocks/{id}', [EmailBlocksController::class, 'update'])
            ->whereNumber('id');
        Route::delete('administration/email-blocks/{id}', [EmailBlocksController::class, 'destroy'])
            ->whereNumber('id');
    });

    Route::middleware(['portal.admin', 'throttle:portal-administration-email-send'])
        ->post('administration/emails', [EmailMessagesController::class, 'store']);
});
