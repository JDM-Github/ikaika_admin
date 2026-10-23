<?php

namespace App\Http\Middleware;

use App\Support\ApiVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Names the backend on every response (2026-10-21, user): X-Api-Version and X-Api-Channel, from ApiVersion.
 *
 * Prepended to the global stack, so it is the outermost middleware and also sees what the inner ones answer on their
 * own - a refusal, a maintenance 503, a CORS preflight - as well as every exception rendered on the way out.
 */
class AddApiVersionHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        foreach (ApiVersion::headers() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
