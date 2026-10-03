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
            'action' => ['required', Rule::in([PortalAccessControl::ON, PortalAccessControl::OFF, PortalAccessControl::REACH, PortalAccessControl::LIST, PortalAccessControl::OVERRIDE])],
            'on' => ['nullable', 'boolean'],
            'reach' => ['nullable', 'string', Rule::in(['lead', 'own', 'team', 'department', 'all'])],
            'includeExecutives' => ['nullable', 'boolean'],
            'mode' => ['nullable', 'required_if:action,'.PortalAccessControl::LIST, Rule::in([PortalAccessControl::LIST_NONE, PortalAccessControl::LIST_ALLOW, PortalAccessControl::LIST_BLOCK])],
            'people' => ['nullable', 'array', 'max:500'],
            'people.*' => ['integer', 'min:1'],
        ]);

        return response()->json($this->access->change($editor, $id, [
            'key' => $validated['key'],
            'action' => $validated['action'],
            'reach' => $validated['reach'] ?? null,
            'includeExecutives' => (bool) ($validated['includeExecutives'] ?? false),
            'mode' => $validated['mode'] ?? null,
            'people' => array_map('intval', $validated['people'] ?? []),
            'on' => isset($validated['on']) ? (bool) $validated['on'] : null,
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
