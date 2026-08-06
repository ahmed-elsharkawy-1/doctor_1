<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the locale from Accept-Language for every API request — SPEC §6.3.
 *
 * Runs before authentication so Form Request validation messages are localised
 * too. That is also why the signed-in user's stored preference cannot be read
 * here — ResolveClinic applies it afterwards, and only when the client sent no
 * explicit header.
 */
class SetApiLocale
{
    /**
     * Request attribute telling later middleware whether the client asked for
     * a specific language.
     */
    public const FROM_HEADER = 'locale_from_header';

    public function handle(Request $request, Closure $next): Response
    {
        $header = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));
        $fromHeader = in_array($header, config('clinic.api.locales'), true);

        app()->setLocale($fromHeader ? $header : config('clinic.api.default_locale'));

        $request->attributes->set(self::FROM_HEADER, $fromHeader);

        return $next($request);
    }
}
