<?php

use App\Http\Controllers\Api\Portal\ClashFlow\ClashFlowProjectsController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

// ClashFlow, the desktop clash app, signs in with the portal session and lists the projects its person is on.
Route::middleware([AuthenticatePortalJwt::class, 'throttle:portal-projects'])
    ->get('clashflow/projects', ClashFlowProjectsController::class);
