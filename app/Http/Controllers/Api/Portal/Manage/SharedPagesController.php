<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSharedPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage / Shared Pages: a developer makes, changes and revokes guest read-only links. The process
 * itself (and the public sign-in) lives in PortalSharedPages; the public side is ShareController.
 */
class SharedPagesController extends Controller
{
    public function __construct(private readonly PortalSharedPages $shares) {}

    public function index(): JsonResponse
    {
        return response()->json($this->shares->index());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->shares->create($this->editor($request), $request));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->shares->update($this->editor($request), $request, $id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json($this->shares->revoke($this->editor($request), $request, $id));
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        return response()->json($this->shares->regenerate($this->editor($request), $request, $id));
    }

    public function qr(int $id): JsonResponse
    {
        return response()->json(['svg' => $this->shares->qr($id)]);
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
