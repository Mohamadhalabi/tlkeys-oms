<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class SetLocaleFromSession
{
    public function handle($request, Closure $next)
    {
        $fallback = Config::get('app.locale', 'en');
        $locale   = session('locale', $fallback);

        // only allow locales you support
        if (! in_array($locale, ['en', 'ar'], true)) {
            $locale = $fallback;
        }

        App::setLocale($locale);

        // If you want Carbon dates to localize:
        try { \Carbon\Carbon::setLocale($locale); } catch (\Throwable $e) {}

        return $next($request);
    }
}
