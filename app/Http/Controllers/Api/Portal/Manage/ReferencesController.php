<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalReferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferencesController extends Controller
{
    public function __construct(private readonly PortalReferences $settings) {}

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

    public function storeJobType(Request $request): JsonResponse
    {
        return response()->json($this->settings->addJobType($this->editor($request), $request));
    }

    public function updateJobType(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->updateJobType($this->editor($request), $request, $id));
    }

    public function destroyJobType(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->removeJobType($this->editor($request), $request, $id));
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        return response()->json($this->settings->addDepartment($this->editor($request), $request));
    }

    public function updateDepartment(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->updateDepartment($this->editor($request), $request, $id));
    }

    public function destroyDepartment(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->removeDepartment($this->editor($request), $request, $id));
    }

    public function storeClient(Request $request): JsonResponse
    {
        return response()->json($this->settings->addClient($this->editor($request), $request));
    }

    public function updateClient(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->updateClient($this->editor($request), $request, $id));
    }

    public function destroyClient(Request $request, int $id): JsonResponse
    {
        return response()->json($this->settings->removeClient($this->editor($request), $request, $id));
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
