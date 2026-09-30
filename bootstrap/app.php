<?php

use App\Http\Middleware\EnsureAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This app is API-only -- there is no login page to redirect to.
        // Without this, an unauthenticated request that doesn't send
        // Accept: application/json (which a mobile HTTP client may not)
        // crashes trying to route() to a 'login' name that doesn't exist,
        // instead of just returning 401 JSON.
        $middleware->redirectGuestsTo(null);

        // Laravel 11+ no longer adds throttle:api to the api group by
        // default -- fine on a LAN only you can reach, not fine once this
        // is on the public internet. 60 req/min per IP (or per user once
        // authenticated) for everything; register/login get a much
        // stricter limit defined in routes/api.php.
        $middleware->throttleApi();

        $middleware->alias(['admin' => EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
