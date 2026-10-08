<?php

use App\Http\Controllers\Api\Portal\Signature\SignatureController;
use App\Http\Middleware\AuthenticatePortalJwt;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // The signed-in employee's own email signature, on Settings. Any member reads their own.
    Route::get('signature', [SignatureController::class, 'show']);
    // The editor's preview of a footer body, for Manage / Signatures.
    Route::middleware('portal.rule:email.library')
        ->post('signature/preview', [SignatureController::class, 'preview']);
    // The default signature footer, read and chosen on Manage / Signatures.
    Route::middleware('portal.rule:email.library')
        ->get('signature/default', [SignatureController::class, 'defaultFooter']);
    Route::middleware('portal.rule:email.library')
        ->put('signature/default', [SignatureController::class, 'setDefaultFooter']);
    // The Exchange transport rules script (one rule per active employee), for IT, from the signature library.
    Route::middleware('portal.rule:email.library')
        ->get('signature/transport-rule', [SignatureController::class, 'transportRule']);
    // Who the rules can cover, so Manage / Signatures can leave some people out.
    Route::middleware('portal.rule:email.library')
        ->get('signature/transport-rule/people', [SignatureController::class, 'rulePeople']);
    // The undo for the rules script: removes every IKAIKA signature rule.
    Route::middleware('portal.rule:email.library')
        ->get('signature/transport-rule/remover', [SignatureController::class, 'transportRuleRemover']);
});
