<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Models\User;
use App\Services\V1\Reports\ReportAccess;
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
            // A WebView the mobile app opened never meets the password form:
            // the app watches for the renew address and quietly asks for a
            // new link. A browser that never came from the app signs in.
            if ($request->cookie(config('clinic.reports.app_cookie'))) {
                return self::private(redirect()->route('reports.app.renew'));
            }

            return redirect()->guest(route('reports.login'));
        }

        $request->attributes->set('clinic', $clinic);

        return self::private($next($request));
    }

    /** The clinic whose reports this account may read, or null — see ReportAccess. */
    public static function clinicFor(?User $user): ?Clinic
    {
        return app(ReportAccess::class)->clinicFor($user);
    }

    public static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
