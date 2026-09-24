<?php

namespace App\Http\Controllers\Api\Portal\Developer;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalDeveloperMode;
use App\Support\Portal\PortalEmployeePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeveloperModeController extends Controller
{
    public function __construct(private readonly PortalDeveloperMode $mode) {}

    public function updateMode(Request $request): JsonResponse
    {
        $developer = $this->developer($request);
        $validated = $request->validate([
            'on' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'max:255'],
            'newPassword' => ['nullable', 'string', 'max:255'],
        ]);

        if ((bool) $validated['on']) {
            $this->mode->activate($developer, $request, (string) ($validated['password'] ?? ''), $validated['newPassword'] ?? null);
        } else {
            $this->mode->deactivate($developer, $request);
        }

        return response()->json([
            'employee' => PortalEmployeePresenter::publicEmployee($developer, $request),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $developer = $this->developer($request);
        $validated = $request->validate([
            'current' => ['required', 'string', 'max:255'],
            'new' => ['required', 'string', 'max:255'],
        ]);
        $this->mode->changePassword($developer, $request, $validated['current'], $validated['new']);

        return response()->json(['changed' => true]);
    }

    private function developer(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }
        // Nobody else learns the feature exists.
        if (! (bool) $actor->is_developer) {
            abort(404);
        }

        return $actor;
    }
}
