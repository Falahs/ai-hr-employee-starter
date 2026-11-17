<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two account states that override any open session:
 * a deactivated member is signed out on the next request, and a member holding a
 * temporary password can reach only the password page until it is replaced.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // a full load, so the page drops the signed-in route list (see AuthenticatedSessionController)
            return \Inertia\Inertia::location(route('login'));
        }

        if ($user->must_change_password && ! $request->routeIs('password.edit', 'password.update', 'logout', 'locale.update')) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
