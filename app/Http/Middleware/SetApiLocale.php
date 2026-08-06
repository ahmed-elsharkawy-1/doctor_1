<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the locale from Accept-Language for every API request — SPEC §6.3.
 *
 * Runs before validation so Form Request messages are localised too. Falls
 * back to the signed-in user's stored locale, then the configured default.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('clinic.api.locales');
        $header = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));

        $locale = in_array($header, $supported, true)
            ? $header
            : ($request->user()?->locale ?? config('clinic.api.default_locale'));

        app()->setLocale(in_array($locale, $supported, true) ? $locale : config('clinic.api.default_locale'));

        return $next($request);
    }
}
