<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// A route that follows a fine-grained rule: portal.rule:<key>, after the JWT has named the person.
class RequirePortalRule
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        // A plain refusal, not a security alert: switching a power off yourself lands here too.
        if (! PortalRules::holds($employee, $request, $key)) {
            abort(403, 'You do not have access to this page.');
        }

        return $next($request);
    }
}
