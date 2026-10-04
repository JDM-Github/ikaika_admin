<?php

use App\Http\Controllers\Api\Portal\Signature\SignatureController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // The signed-in employee's own email signature, on Settings. Any member reads their own.
    Route::get('signature', [SignatureController::class, 'show']);
    // The same signature as a downloadable .htm (logo inlined), for Outlook.
    Route::get('signature/document', [SignatureController::class, 'document']);
    // The same signature as a one-click Windows Outlook installer (.cmd).
    Route::get('signature/installer', [SignatureController::class, 'installer']);
    // The editor's preview of a footer body, for Manage / Signatures.
    Route::middleware('portal.rule:email.library')
        ->post('signature/preview', [SignatureController::class, 'preview']);
    // The org-wide Exchange transport rule script, for IT, from the signature library.
    Route::middleware('portal.rule:email.library')
        ->get('signature/transport-rule', [SignatureController::class, 'transportRule']);
});
