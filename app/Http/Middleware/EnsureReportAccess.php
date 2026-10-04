<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only accounts flagged for reports, and only their own clinic.
 *
 * The clinic is always the signed-in account's — never anything in the
 * address — so a link cannot be edited into another clinic's numbers. The
 * report carries income and patients' phones, so every response is kept out
 * of caches, search engines and other sites' referrer logs.
 */
class EnsureReportAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $clinic = self::clinicFor($request->user());

        if ($clinic === null) {
            return redirect()->guest(route('reports.login'));
        }

        $request->attributes->set('clinic', $clinic);

        return self::private($next($request));
    }

    /** The clinic whose reports this account may read, or null. */
    public static function clinicFor(?User $user): ?Clinic
    {
        if ($user === null || ! $user->is_active || ! $user->can_access_reports || ! $user->role->usesMobileApp()) {
            return null;
        }

        $clinic = $user->activeClinic();

        return $clinic !== null && $clinic->is_active && $clinic->reports_enabled ? $clinic : null;
    }

    public static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
