<?php

use App\Http\Controllers\Api\Portal\Projects\ProjectsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-projects')
        ->get('projects', [ProjectsController::class, 'index']);
});
