<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAccessDenied;
use App\Support\Portal\PortalRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePortalAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        if (! PortalRole::canManageUsers($employee->role, $employee->role_level)) {
            PortalAccessDenied::abort($request, 'Administrator access is required.');
        }

        return $next($request);
    }
}
