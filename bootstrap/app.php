<?php

use App\Http\Middleware\RequirePortalAdmin;
use App\Support\ApiPath;
use App\Support\Portal\PortalForbiddenException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix(ApiPath::routePrefix())
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'portal.admin' => RequirePortalAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiPath::isApiRequest($request) || $request->expectsJson(),
        );

        $exceptions->render(function (HttpException $e, Request $request) {
            if (! ApiPath::isApiRequest($request)) {
                return null;
            }

            $payload = ['message' => $e->getMessage()];
            if ($e instanceof PortalForbiddenException) {
                $payload['warning'] = $e->warning;
                $payload['notified'] = $e->notified;
            }

            return response()->json($payload, $e->getStatusCode());
        });
    })->create();
