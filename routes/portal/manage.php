<?php

use App\Http\Controllers\Api\Portal\Manage\UsersController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    Route::middleware(['portal.admin', 'throttle:portal-manage-users'])
        ->get('manage/users', [UsersController::class, 'index']);
    Route::middleware(['portal.admin', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/role', [UsersController::class, 'updateRole'])
        ->whereNumber('id');
});
