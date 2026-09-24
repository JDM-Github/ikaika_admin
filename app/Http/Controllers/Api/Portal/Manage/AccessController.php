<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessController extends Controller
{
    public function __construct(private readonly PortalAccessControl $access) {}

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->access->show($this->editor($request), $id, $request));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $editor = $this->editor($request);
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'action' => ['required', Rule::in([PortalAccessControl::ON, PortalAccessControl::OFF, PortalAccessControl::REACH])],
            'reach' => ['nullable', 'string', Rule::in(['own', 'team', 'department', 'all'])],
            'includeExecutives' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->access->change($editor, $id, [
            'key' => $validated['key'],
            'action' => $validated['action'],
            'reach' => $validated['reach'] ?? null,
            'includeExecutives' => (bool) ($validated['includeExecutives'] ?? false),
        ], $request));
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
