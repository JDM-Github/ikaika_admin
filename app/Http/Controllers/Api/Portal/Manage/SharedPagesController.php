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
 * Every answer rides in `data`, the portal's envelope.
 */
class SharedPagesController extends Controller
{
    public function __construct(private readonly PortalSharedPages $shares) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->shares->index($this->editor($request), $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->shares->create($this->editor($request), $request)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->shares->update($this->editor($request), $request, $id)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->shares->revoke($this->editor($request), $request, $id)]);
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->shares->regenerate($this->editor($request), $request, $id)]);
    }

    public function qr(int $id): JsonResponse
    {
        return response()->json(['data' => ['svg' => $this->shares->qr($id)]]);
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
