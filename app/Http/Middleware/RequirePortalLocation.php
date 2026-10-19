<?php

namespace App\Http\Middleware;

use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalLocationExemptions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Company policy (2026-10-01): every portal write is located. A write without X-Portal-Location is
 * refused with 428 and code location_required, which the portal answers with its location modal.
 * The label may be the device's place, its coordinates, or the network guess when the device timed
 * out: a located action, not proof of where someone was (docs/power/location-and-accountability-plan.md).
 *
 * Exempt, as the portal's ApiManager is: signing in, Developer mode's own password, marking
 * notices read when a page opens, and reporting a bug -- a report is anonymous, so where it was sent
 * from is not asked (2026-10-13). A person on location_exemptions goes without one too (2026-10-19).
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
        // Only the collection: MIS changing a report's status is located like any other write.
        '*/portal/bug-reports',
        // The composer's email preview: a read-only dry run, carried on a POST because the draft is
        // too long for a query string. It writes nothing, so it asks for no location.
        '*/portal/administration/emails/preview',
        // The remarks tidy-up: a POST that stores nothing, only returns wording. Same reason.
        '*/portal/remarks/format',
        '*/portal/remarks/format-many',
    ];

    public function __construct(private readonly PortalJwt $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is(...self::EXEMPT)) {
            return $next($request);
        }

        if (trim((string) $request->header('X-Portal-Location', '')) === '' && ! $this->isExemptSession($request)) {
            return response()->json([
                'message' => 'Saving needs your location. Turn location on for the portal and try again.',
                'code' => 'location_required',
            ], 428);
        }

        return $next($request);
    }

    // Signing in has not run yet at this point, so the token is read only to learn who is asking; the
    // auth middleware after this still checks it in full.
    private function isExemptSession(Request $request): bool
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return false;
        }
        try {
            $payload = $this->jwt->parse(trim(substr($header, 7)));
        } catch (Throwable) {
            return false;
        }
        $sub = $payload['sub'] ?? null;

        return ($payload['guest'] ?? false) !== true && is_numeric($sub) && PortalLocationExemptions::covers((int) $sub);
    }
}
