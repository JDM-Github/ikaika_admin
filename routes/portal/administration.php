<?php

use App\Http\Controllers\Api\Portal\Administration\RecycleBinController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-administration-recycle-bin')->group(function (): void {
        Route::get('administration/recycle-bin', [RecycleBinController::class, 'index']);
        Route::get('administration/recycle-bin/{id}', [RecycleBinController::class, 'show'])
            ->whereNumber('id');
    });

    Route::middleware('throttle:portal-administration-recycle-bin-write')
        ->post('administration/recycle-bin/{id}/restore', [RecycleBinController::class, 'restore'])
        ->whereNumber('id');
});
