<?php

use App\Http\Controllers\Api\Portal\ClashFlow\ClashFlowProjectsController;
use App\Http\Controllers\Api\Portal\ClashFlow\ClashFlowStatesController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

// ClashFlow, the desktop clash app: its projects (PortalClashFlowProjects) and their mock workflow state
// (PortalClashFlowStates). It signs in with the portal session.
Route::middleware([AuthenticatePortalJwt::class, 'throttle:clashflow'])->group(function (): void {
    Route::get('clashflow/projects', [ClashFlowProjectsController::class, 'index']);
    Route::get('clashflow/linkable', [ClashFlowProjectsController::class, 'linkable']);
    Route::post('clashflow/projects', [ClashFlowProjectsController::class, 'store']);

    Route::get('clashflow/states', [ClashFlowStatesController::class, 'index']);
    Route::put('clashflow/states/{key}', [ClashFlowStatesController::class, 'update']);
    Route::delete('clashflow/states', [ClashFlowStatesController::class, 'destroy']);
});
