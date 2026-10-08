<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company policy (2026-10-01): every portal write is located. A write without X-Portal-Location is
 * refused with 428 and code location_required, which the portal answers with its location modal.
 * The label may be the device's place, its coordinates, or the network guess when the device timed
 * out: a located action, not proof of where someone was (docs/power/location-and-accountability-plan.md).
 *
 * Exempt, as the portal's ApiManager is: signing in, Developer mode's own password, and marking
 * notices read when a page opens.
 */
class RequirePortalLocation
{
    private const EXEMPT = [
        '*/portal/auth/*',
        // A guest signing into a shared page is not an employee act, and nothing is written.
        '*/portal/share/*',
        // A candidate on a registration link is not an employee either.
        '*/portal/register/*',
        '*/portal/developer/mode',
        '*/portal/developer/password',
        '*/portal/user/notifications/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is(...self::EXEMPT)) {
            return $next($request);
        }

        if (trim((string) $request->header('X-Portal-Location', '')) === '') {
            return response()->json([
                'message' => 'Saving needs your location. Turn location on for the portal and try again.',
                'code' => 'location_required',
            ], 428);
        }

        return $next($request);
    }
}
