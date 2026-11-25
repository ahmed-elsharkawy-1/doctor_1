<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Services\V1\Branches\ActiveBranch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web counterpart of ResolveClinic: it answers the same questions and
 * puts the same `clinic` attribute on the request, but sends a browser to the
 * login screen instead of returning a JSON envelope.
 *
 * As on the API, `clinic_id` is never accepted from the client — it is only
 * ever derived from the signed-in account (SPEC §6.6).
 */
class EnsureClinicSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('app.login'));
        }

        // A platform operator has a perfectly good session — for the panel.
        // Signing them out here would drop them out of Filament for mistyping
        // an address, so send them where they belong instead.
        if ($user->role->usesPanel()) {
            return redirect()->to(config('clinic.panel.path'));
        }

        $clinic = $user->is_active && $user->role->usesMobileApp()
            ? $user->activeClinic()
            : null;

        if ($clinic === null || ! $clinic->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('app.login')
                ->withErrors(['email' => __($this->reason($user, $clinic))]);
        }

        $branches = app(ActiveBranch::class);
        $requested = $request->session()->get(ActiveBranch::SESSION_KEY);
        $branch = $branches->resolve($user, $clinic, is_numeric($requested) ? (int) $requested : null);

        // Taken off every branch while signed in (ELA-17).
        if ($branch === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('app.login')
                ->withErrors(['email' => __('auth.branch_not_assigned')]);
        }

        // Only resolved here, never remembered: a shared login open in two
        // places would otherwise flip its "last used" branch on every
        // request. The switcher remembers a choice.
        $request->session()->put(ActiveBranch::SESSION_KEY, $branch->id);

        $request->attributes->set('clinic', $clinic);
        $request->attributes->set('branch', $branch);

        app()->setLocale(
            in_array($user->locale, config('clinic.api.locales'), true)
                ? $user->locale
                : config('clinic.api.default_locale'),
        );

        return $next($request);
    }

    /**
     * The same distinctions ResolveClinic draws, so a locked-out user is told
     * the same thing whichever client they used.
     */
    private function reason(mixed $user, ?Clinic $clinic): string
    {
        return match (true) {
            ! $user->is_active => 'auth.account_inactive',
            ! $user->role->usesMobileApp() => 'auth.role_not_allowed',
            $clinic === null => 'auth.clinic_not_assigned',
            default => 'auth.clinic_inactive',
        };
    }
}
