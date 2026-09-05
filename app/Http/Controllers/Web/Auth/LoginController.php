<?php

namespace App\Http\Controllers\Web\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\V1\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Clinic sign-in for the web app.
 *
 * The credential rules are not restated here — AuthService::authenticate is
 * the same code the mobile API calls. All this adds is a session instead of a
 * token, and an error on the form instead of a JSON envelope.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        app()->setLocale(config('clinic.api.default_locale'));

        return view('app.auth.login');
    }

    public function store(Request $request, AuthService $auth): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $user = $auth->authenticate($credentials['email'], $credentials['password']);
        } catch (ApiException $e) {
            // ApiException renders itself as JSON; on the web it belongs on
            // the form. The message is already translated.
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        Auth::guard('web')->login($user, remember: $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('app.queue'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.login');
    }
}
