<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared with every page. The translation table travels once per full page load
     * (Inertia keeps it between visits); changing language reloads it.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'dir' => Locale::dir($locale),
            'locales' => Locale::SUPPORTED,
            'translations' => fn () => Locale::messages($locale),
            'auth' => [
                // Only what the interface needs, never the whole model. The server checks
                // permissions itself; this list only decides what the interface shows.
                'user' => $user ? [
                    ...$user->only(['id', 'name', 'email', 'is_admin', 'locale', 'email_verified_at', 'must_change_password']),
                    'permissions' => $user->effectivePermissions(),
                ] : null,
            ],
            // Shown once: the temporary password exists only in this one response.
            'flash' => fn () => [
                'status' => $request->session()->get('status'),
                'temporary_password' => $request->session()->get('temporary_password'),
            ],
        ];
    }
}
