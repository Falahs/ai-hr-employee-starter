<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** لغة الطلب: لغة حساب المستخدم، وقبل الدخول لغة الجلسة، وإلا العربية. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->session()->get('locale');

        app()->setLocale(Locale::valid($locale) ? $locale : config('app.locale', 'ar'));

        return $next($request);
    }
}
