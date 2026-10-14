<?php

namespace App\Http\Controllers\Api\Portal\Auth;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalAdvancedSettings;
use Illuminate\Http\JsonResponse;

/**
 * What the login screen may offer, before anyone is signed in: today, whether the ID-number box is
 * on (the Advanced settings switch). Public on purpose -- the screen has no session to read it with.
 */
class LoginMethodsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['idLogin' => PortalAdvancedSettings::idLogin()]]);
    }
}
