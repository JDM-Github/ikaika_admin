<?php

namespace App\Http\Controllers\Api\Portal\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAdvancedSettings;
use App\Support\Portal\PortalDeveloperMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedSettingsController extends Controller
{
    public function __construct(private readonly PortalAdvancedSettings $advanced) {}

    public function show(Request $request): JsonResponse
    {
        $this->developer($request);

        return response()->json(['data' => [
            'notificationEmails' => PortalAdvancedSettings::notificationEmails(),
            'idLogin' => PortalAdvancedSettings::idLogin(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $this->developer($request);
        $validated = $request->validate([
            'notificationEmails' => ['nullable', 'boolean'],
            'idLogin' => ['nullable', 'boolean'],
        ]);
        $notificationEmails = $validated['notificationEmails'] ?? null;
        $idLogin = $validated['idLogin'] ?? null;
        if ($notificationEmails === null && $idLogin === null) {
            abort(422, 'Give at least one switch.');
        }

        if ($notificationEmails !== null) {
            $this->advanced->setNotificationEmails($actor, $request, (bool) $notificationEmails);
        }
        if ($idLogin !== null) {
            $this->advanced->setIdLogin($actor, $request, (bool) $idLogin);
        }

        return response()->json(['data' => [
            'notificationEmails' => PortalAdvancedSettings::notificationEmails(),
            'idLogin' => PortalAdvancedSettings::idLogin(),
        ]]);
    }

    private function developer(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee || ! PortalDeveloperMode::isOn($actor, $request)) {
            abort(403, 'Switch on Developer mode to use Advanced settings.');
        }

        return $actor;
    }
}
