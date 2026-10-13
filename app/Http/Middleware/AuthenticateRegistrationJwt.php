<?php

namespace App\Http\Middleware;

use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalRegistrations;
use App\Support\Portal\PortalRegistrationSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A candidate on their registration link: the session token names the entry, and only the register/*
 * session routes are guarded by this middleware, so the entry id always comes from the token and
 * never from the request. Unlike a guest, the session may write -- but only through those routes.
 */
class AuthenticateRegistrationJwt
{
    public function __construct(private readonly PortalJwt $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            abort(401, 'Authentication is required.');
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            abort(401, 'Authentication is required.');
        }

        try {
            $payload = $this->jwt->parse($token);
        } catch (Throwable) {
            abort(401, 'The session is invalid or has expired.');
        }

        $registrationId = $payload['registration'] ?? null;
        $row = is_numeric($registrationId)
            ? DB::connection('portal')->table('registrations')->where('id', (int) $registrationId)->first()
            : null;
        if ($row === null) {
            abort(401, 'This link is no longer active.');
        }
        // A revoked link is a closed registration (2026-10-12), and says so to the candidate mid-session too.
        if ($row->revoked_at !== null) {
            abort(401, PortalRegistrations::CLOSED);
        }
        if ($row->expires_at !== null && strtotime((string) $row->expires_at) <= now()->getTimestamp()) {
            abort(401, 'This link has expired.');
        }

        $limitKey = 'portal-register:'.$row->id;
        if (RateLimiter::tooManyAttempts($limitKey, PortalRegistrations::SESSION_REQUESTS_PER_MINUTE)) {
            abort(429, 'This registration is being used too often. Try again in a minute.');
        }
        RateLimiter::hit($limitKey, 60);

        $request->attributes->set('portalRegistration', new PortalRegistrationSession(
            (int) $row->id,
            (string) $row->email,
            (string) $row->candidate_name,
        ));

        return $next($request);
    }
}
