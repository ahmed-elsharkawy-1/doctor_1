<?php

namespace App\Http\Controllers\Web\Reports;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureReportAccess;
use App\Services\V1\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in to the reports page, with the same account as the clinic app.
 *
 * Every refusal reads the same — wrong password, not flagged, reports off — so
 * the form says nothing about which accounts exist or what they may do.
 */
class LoginController extends Controller
{
    public function show(Request $request): Response
    {
        if (EnsureReportAccess::clinicFor($request->user()) !== null) {
            return redirect()->route('reports.index');
        }

        return EnsureReportAccess::private(response()->view('reports.login'));
    }

    public function store(Request $request, AuthService $auth): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $user = $auth->authenticate($credentials['email'], $credentials['password']);
        } catch (ApiException) {
            $user = null;
        }

        if (EnsureReportAccess::clinicFor($user) === null) {
            throw ValidationException::withMessages(['email' => __('auth.invalid_credentials')]);
        }

        // Remembered for months, not the session's two hours: the doctor
        // opens this from a WhatsApp message each morning, on her own phone.
        $guard = Auth::guard('web');
        $guard->setRememberDuration((int) config('clinic.reports.remember_days') * 24 * 60);
        $guard->login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('reports.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('reports.login');
    }
}
