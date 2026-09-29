<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // Behind a TLS-terminating reverse proxy (Caddy, host Nginx, a load balancer), trust its
        // X-Forwarded-* headers so HTTPS, client IPs and redirects are correct. Empty = trust none.
        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render HTTP errors as Inertia pages for browser requests; API/JSON clients keep JSON responses.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('error', 'The page expired. Please try again.');
            }

            $alwaysRender = [403, 404];
            $renderOutsideDebug = [500, 503];

            if (in_array($status, $alwaysRender, true)
                || (in_array($status, $renderOutsideDebug, true) && ! app()->environment(['local', 'testing']))) {
                return Inertia::render('error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
