<?php

use App\Http\Controllers\Api\Portal\Projects\ProjectsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware('throttle:portal-projects')
        ->get('projects', [ProjectsController::class, 'index']);
    // Track Projects follows its rule: its own read, so the list obeys its reach, not View Projects'.
    Route::middleware(['throttle:portal-projects', 'portal.rule:projects.track'])
        ->get('projects/track', [ProjectsController::class, 'track']);
});
