<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// A route for someone's own work: portal.own:<key>|<key>. Checks like portal.rule, and in Developer mode past Default a write waits for confirmation.
class RequirePortalOwnRule
{
    public function handle(Request $request, Closure $next, string $keys): Response
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        PortalRules::requireOwn($employee, $request, ...explode('|', $keys));

        return $next($request);
    }
}
