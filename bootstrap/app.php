<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // خلف Traefik (على الخادم): الطلب يصل للحاوية HTTP، والأصل HTTPS
        $middleware->trustProxies(at: '*');

        $middleware->alias(['admin' => EnsureAdmin::class]);

        $middleware->web(append: [
            EnsureActiveAccount::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ContentSecurityPolicy::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A route limit (throttle:...) hit from a form: Inertia would show Laravel's English 429 page in a
        // pop-up. Instead the message goes back under the form's first field, like any validation error.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return null;
            }
            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $field = array_key_first($request->except(['_token', '_method'])) ?? 'throttle';

            // 303, not back()'s 302: after a PUT the browser would repeat the PUT on a 302, and hit the limit again
            return back(303)->withErrors([$field => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])]);
        });
    })->create();
