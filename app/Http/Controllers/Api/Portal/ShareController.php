<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalGuest;
use App\Support\Portal\PortalSharedPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public side of a shared page. No auth middleware: the code in the path names the share, and
 * the password (plus the mailed code when an email is bound) is what signs the guest in. A signed-in
 * guest comes back through session to confirm the link is still live.
 */
class ShareController extends Controller
{
    public function __construct(private readonly PortalSharedPages $shares) {}

    public function info(string $code): JsonResponse
    {
        return response()->json($this->shares->info($code));
    }

    public function access(Request $request, string $code): JsonResponse
    {
        return response()->json($this->shares->access($request, $code));
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        return response()->json($this->shares->verify($request, $code));
    }

    public function resend(Request $request, string $code): JsonResponse
    {
        return response()->json($this->shares->resend($request, $code));
    }

    public function session(Request $request): JsonResponse
    {
        $guest = $request->attributes->get('portalShare');
        if (! $guest instanceof PortalGuest) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->shares->session($guest));
    }
}
