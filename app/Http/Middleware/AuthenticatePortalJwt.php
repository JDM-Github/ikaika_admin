<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalManageUserPresenter;
use Closure;
use Illuminate\Http\Request;
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

        return $next($request);
    }
}
