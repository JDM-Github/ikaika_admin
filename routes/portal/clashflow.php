<?php

use App\Http\Controllers\Api\Portal\ClashFlow\ClashFlowProjectsController;
use App\Http\Controllers\Api\Portal\ClashFlow\ClashFlowStatesController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

// ClashFlow, the desktop clash app, signs in with the portal session and lists the projects its person is on.
Route::middleware([AuthenticatePortalJwt::class, 'throttle:portal-projects'])
    ->get('clashflow/projects', ClashFlowProjectsController::class);

// Its mock workflow state: one document per project plus the shared playbook library (PortalClashFlowStates).
Route::middleware([AuthenticatePortalJwt::class, 'throttle:clashflow'])->group(function (): void {
    Route::get('clashflow/states', [ClashFlowStatesController::class, 'index']);
    Route::put('clashflow/states/{key}', [ClashFlowStatesController::class, 'update']);
    Route::delete('clashflow/states', [ClashFlowStatesController::class, 'destroy']);
});
