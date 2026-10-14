<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalGuest;
use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalManageUserPresenter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticatePortalJwt
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

        if (($payload['guest'] ?? false) === true) {
            $this->authenticateGuest($request, $payload);

            return $next($request);
        }

        $employeeId = $payload['sub'] ?? null;
        $employee = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->find($employeeId);
        if ($employee === null) {
            abort(401, 'The session is invalid or has expired.');
        }

        $status = strtolower(trim((string) $employee->status));
        if ($status !== 'active') {
            abort(401, 'This account is not active.');
        }

        $request->attributes->set('portalEmployee', $employee);
        $request->attributes->set('portalSessionId', is_string($payload['sid'] ?? null) ? $payload['sid'] : null);

        return $next($request);
    }

    /**
     * A shared page's guest: no employee, no writes, and the share re-read on every request so a
     * revoke or an expiry ends a live session at once. The share's own rate cap is enforced here.
     *
     * @param  array<string, mixed>  $payload
     */
    private function authenticateGuest(Request $request, array $payload): void
    {
        if (! $request->isMethod('GET') && ! $this->isGuestSend($request)) {
            abort(403, 'Guest access is read-only.');
        }

        $shareId = $payload['share'] ?? null;
        $share = is_numeric($shareId)
            ? DB::connection('portal')->table('shared_pages')->where('id', (int) $shareId)->first()
            : null;
        if ($share === null || $share->revoked_at !== null) {
            abort(401, 'This shared page is no longer active.');
        }
        if ($share->expires_at !== null && strtotime((string) $share->expires_at) <= now()->getTimestamp()) {
            abort(401, 'This shared page has expired.');
        }

        $limitKey = 'portal-share:'.$share->id;
        if (RateLimiter::tooManyAttempts($limitKey, (int) $share->rate_per_minute)) {
            abort(429, 'This shared page is being used too often. Try again in a minute.');
        }
        RateLimiter::hit($limitKey, 60);

        $rules = DB::connection('portal')
            ->table('shared_page_rules')
            ->where('share_id', $share->id)
            ->orderBy('rule_key')
            ->pluck('reach', 'rule_key')
            ->all();

        $request->attributes->set('portalShare', new PortalGuest((int) $share->id, $rules));
        // An empty employee stands in so services reading the actor get nulls instead of crashing;
        // a department-reach share lends it the scope's department so the reach filters still work.
        $standIn = new Employee;
        $scopeDepartment = trim((string) ($share->reach_department ?? ''));
        if ($scopeDepartment !== '') {
            $standIn->forceFill(['department' => $scopeDepartment]);
        }
        $request->attributes->set('portalEmployee', $standIn);
        $request->attributes->set('portalSessionId', is_string($payload['sid'] ?? null) ? $payload['sid'] : null);

        if ($share->last_used_at === null || strtotime((string) $share->last_used_at) < now()->getTimestamp() - 60) {
            DB::connection('portal')->table('shared_pages')->where('id', $share->id)->update(['last_used_at' => now()]);
        }
    }

    /*
     * The two writes a guest may make: sending email from the composer, and asking the server to
     * build a preview (a POST only because the draft is too long for a query string). The send lands
     * in All Emails like anyone's, and the routes' own rules (email.send, lent by the share's email
     * grant) still gate them, so a share without the page cannot reach these paths.
     */
    private function isGuestSend(Request $request): bool
    {
        return $request->isMethod('POST')
            && ($request->is('*/administration/emails') || $request->is('*/administration/emails/preview'));
    }
}
