<?php

use App\Http\Controllers\Api\PortalAuthController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [PortalAuthController::class, 'login']);
Route::get('auth/me', [PortalAuthController::class, 'me'])
    ->middleware(AuthenticatePortalJwt::class);
