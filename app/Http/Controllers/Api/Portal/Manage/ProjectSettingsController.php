<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectSettingsController extends Controller
{
    public function __construct(private readonly PortalProjectSettings $settings) {}

    public function index(): JsonResponse
    {
        return response()->json($this->settings->index());
    }

    public function storeScope(Request $request): JsonResponse
    {
        return response()->json($this->settings->addScope($this->editor($request), $request));
    }

    public function updateScope(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->updateScope($this->editor($request), $request, $id));
    }

    public function destroyScope(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->removeScope($this->editor($request), $request, $id));
    }

    private function editor(Request $request): Employee
    {
        $editor = $request->attributes->get('portalEmployee');
        if (! $editor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $editor;
    }
}
