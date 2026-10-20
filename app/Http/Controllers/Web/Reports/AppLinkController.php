<?php

namespace App\Http\Controllers\Web\Reports;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureReportAccess;
use App\Services\V1\Reports\ReportWebLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reports page as the mobile app opens it.
 *
 * `open` spends a link from ReportWebLinkService and signs the WebView in —
 * an ordinary session, not the website's remembered login. `renew` is the
 * fixed address the app watches for: whenever the WebView cannot be signed
 * in, it is sent here, and the app asks for a new link instead of showing it.
 */
class AppLinkController extends Controller
{
    public function __construct(private readonly ReportWebLinkService $links) {}

    public function open(Request $request, string $token): RedirectResponse
    {
        $user = $this->links->redeem($token);

        if ($user === null) {
            return $this->toRenew();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // App mode lives in the session, so every period the user taps keeps it.
        $request->session()->put('reports.app', true);

        $response = redirect()->route('reports.index')->withCookie(cookie(
            config('clinic.reports.app_cookie'),
            '1',
            (int) config('clinic.reports.app_cookie_days') * 24 * 60,
        ));

        return EnsureReportAccess::private($response);
    }

    public function renew(): Response
    {
        App::setLocale(config('clinic.api.default_locale'));

        return EnsureReportAccess::private(response()->view('reports.app-renew'));
    }

    private function toRenew(): RedirectResponse
    {
        return EnsureReportAccess::private(redirect()->route('reports.app.renew'));
    }
}
