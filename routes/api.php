<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

$channel = config('products.channel', 'development');

Route::prefix($channel)->group(function (): void {
    Route::get('/', [CatalogController::class, 'index']);
    Route::get('{product}/health', [ProductController::class, 'health']);
    Route::get('{product}', [ProductController::class, 'show']);

    require __DIR__.'/portal/api.php';

    Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
        Route::get('{product}/{resource}', [ResourceController::class, 'index']);
        Route::get('{product}/{resource}/{id}', [ResourceController::class, 'show']);
    });
});
