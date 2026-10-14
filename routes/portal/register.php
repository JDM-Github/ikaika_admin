<?php

use App\Http\Controllers\Api\Portal\Register\RegisterController;
use App\Http\Middleware\AuthenticateRegistrationJwt;
use Illuminate\Support\Facades\Route;

/*
 * A registration link (docs/power/onboarding-plan.md): the candidate signs in with the password and
 * the code mailed to the bound address, then autosaves their details, uploads documents and submits
 * through the session routes. There is no employee session here, and no portal rule: the link is the
 * grant, and the session middleware takes the entry id from the token alone.
 */
Route::middleware('throttle:portal-register-auth')->group(function (): void {
    Route::post('register/{code}/access', [RegisterController::class, 'access']);
    Route::post('register/{code}/verify', [RegisterController::class, 'verify']);
    Route::post('register/{code}/resend', [RegisterController::class, 'resend']);
});

Route::middleware(AuthenticateRegistrationJwt::class)->group(function (): void {
    // Before register/{code}, so the session path is never read as a code.
    Route::get('register/session', [RegisterController::class, 'session']);
    Route::patch('register/details', [RegisterController::class, 'details']);
    Route::post('register/documents', [RegisterController::class, 'storeDocument']);
    Route::get('register/documents/{id}', [RegisterController::class, 'showDocument'])->whereNumber('id');
    Route::delete('register/documents/{id}', [RegisterController::class, 'destroyDocument'])->whereNumber('id');
    Route::post('register/submit', [RegisterController::class, 'submit']);
    // The address pickers' lists (2026-10-13): the provinces, then a province's cities, then a city's barangays.
    Route::get('register/places/provinces', [RegisterController::class, 'provinces']);
    Route::get('register/places/provinces/{code}/cities', [RegisterController::class, 'cities'])->where('code', '[0-9]{9}');
    Route::get('register/places/cities/{code}/barangays', [RegisterController::class, 'barangays'])->where('code', '[0-9]{9}');
});

// Last, so the fixed paths above are never read as a code.
Route::get('register/{code}', [RegisterController::class, 'info']);
