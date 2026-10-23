<?php

use App\Http\Controllers\Api\Bitbucket\BitbucketWebhookController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\Jira\JiraWebhookController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WorkspaceGridController;
use App\Http\Controllers\Api\WorkspaceSchemaController;
use App\Http\Controllers\Api\WorkspaceViewController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

$channel = config('products.channel', 'development');

Route::prefix($channel)->group(function (): void {
    Route::get('/', [CatalogController::class, 'index']);
    Route::get('{product}/health', [ProductController::class, 'health']);
    Route::get('{product}', [ProductController::class, 'show']);

    require __DIR__.'/portal/api.php';

    // Bitbucket calls this on a push, not a signed-in person; its HMAC signature is the only way in.
    Route::middleware('throttle:bitbucket-webhook')->post('bitbucket/webhook', BitbucketWebhookController::class);

    // Jira's admin webhook for Issues & Requests (2026-10-19); likewise signed, and only that.
    Route::middleware('throttle:jira-webhook')->post('jira/webhook', JiraWebhookController::class);

    // The typed workspace endpoints; the old raw {product}/{resource} model dump was removed
    // (2026-10-23 security audit F-001).
    Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
        Route::middleware('throttle:workspace')->group(function (): void {
            Route::get('{product}/_schema', [WorkspaceSchemaController::class, 'index']);
            Route::get('{product}/_schema/{table}', [WorkspaceSchemaController::class, 'show']);
            Route::get('{product}/_grid/{table}', [WorkspaceGridController::class, 'index']);
            Route::get('{product}/_grid/{table}/{id}', [WorkspaceGridController::class, 'show']);
            Route::get('{product}/_views/{table}', [WorkspaceViewController::class, 'index']);
        });

        Route::middleware('throttle:workspace-write')->group(function (): void {
            Route::patch('{product}/_grid/{table}/{id}', [WorkspaceGridController::class, 'update']);
            Route::post('{product}/_views/{table}', [WorkspaceViewController::class, 'store']);
            Route::delete('{product}/_views/{id}', [WorkspaceViewController::class, 'destroy'])->whereNumber('id');
        });
    });

});
