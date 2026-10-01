<?php

use App\Http\Controllers\Api\Portal\Projects\ProjectsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // View Projects follows its rule; the list is cut to that rule's reach in PortalViewProjects.
    Route::middleware(['throttle:portal-projects', 'portal.rule:projects.view'])
        ->get('projects', [ProjectsController::class, 'index']);
    // Track Projects follows its rule: its own read, so the list obeys its reach, not View Projects'.
    Route::middleware(['throttle:portal-projects', 'portal.rule:projects.track'])
        ->get('projects/track', [ProjectsController::class, 'track']);
    /*
     * No rule middleware on the form's routes: PortalProjectEditor checks Add projects on create, and
     * Edit projects on the rest, because a project's lead may edit their own without it.
     */
    Route::middleware('throttle:portal-projects')
        ->get('projects/options', [ProjectsController::class, 'options']);
    Route::middleware('throttle:portal-projects')
        ->get('projects/{id}', [ProjectsController::class, 'show'])
        ->whereNumber('id');
    Route::middleware('throttle:portal-projects-write')
        ->post('projects', [ProjectsController::class, 'store']);
    Route::middleware('throttle:portal-projects-write')
        ->patch('projects/{id}', [ProjectsController::class, 'update'])
        ->whereNumber('id');
    Route::middleware('throttle:portal-projects-write')
        ->put('projects/{id}/scopes', [ProjectsController::class, 'scopes'])
        ->whereNumber('id');
    /*
     * No rule middleware: Delete projects is checked in PortalProjectDeletion, because a project's lead
     * may delete their own without it. The write throttle is the recycle bin's, where the row lands.
     */
    Route::middleware('throttle:portal-administration-recycle-bin-write')
        ->delete('projects/{id}', [ProjectsController::class, 'destroy'])
        ->whereNumber('id');
});
