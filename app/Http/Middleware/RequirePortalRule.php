<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// A route that follows a fine-grained rule: portal.rule:<key>, or <key>|<key> when any one will do.
class RequirePortalRule
{
    public function handle(Request $request, Closure $next, string $keys): Response
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        PortalRules::require($employee, $request, ...explode('|', $keys));

        return $next($request);
    }
}
