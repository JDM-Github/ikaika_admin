<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\WorkspaceGridController;
use App\Http\Controllers\Api\WorkspaceSchemaController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

$channel = config('products.channel', 'development');

Route::prefix($channel)->group(function (): void {
    Route::get('/', [CatalogController::class, 'index']);
    Route::get('{product}/health', [ProductController::class, 'health']);
    Route::get('{product}', [ProductController::class, 'show']);

    require __DIR__.'/portal/api.php';

    // Before the {product}/{resource} catch-all below, which would otherwise swallow
    // _schema and _grid and report them as unknown resources.
    Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
        Route::middleware('throttle:workspace')->group(function (): void {
            Route::get('{product}/_schema', [WorkspaceSchemaController::class, 'index']);
            Route::get('{product}/_schema/{table}', [WorkspaceSchemaController::class, 'show']);
            Route::get('{product}/_grid/{table}', [WorkspaceGridController::class, 'index']);
            Route::get('{product}/_grid/{table}/{id}', [WorkspaceGridController::class, 'show']);
        });
    });

    Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
        Route::get('{product}/{resource}', [ResourceController::class, 'index']);
        Route::get('{product}/{resource}/{id}', [ResourceController::class, 'show']);
    });
});
