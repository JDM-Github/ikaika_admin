<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAccessDenied;
use App\Support\Portal\PortalPreview;
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

        // A developer can do everything an admin can, whatever their role column says.
        if (! PortalPreview::actsAsDeveloper($employee, $request) && ! PortalRole::canManageUsers($employee->role, $employee->role_level)) {
            PortalAccessDenied::abort($request, 'Administrator access is required.');
        }

        return $next($request);
    }
}
