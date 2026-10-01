<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A password in front of a non-production copy of the app.
 *
 * Staging is the whole app on a public address. Without this, a patient who
 * found it through a search engine could book a visit at a clinic that will
 * never see it. Production never sets the password, and with none set this
 * does nothing at all.
 *
 * Left open, because each has its own authentication and none can answer a
 * browser's password prompt: the API (a staging build of the mobile app uses
 * it), Meta's webhook (signed), and the container health check.
 */
class StagingGate
{
    private const OPEN = ['api/*', 'webhooks/*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('clinic.staging_gate.password');

        if ($password === '') {
            return $next($request);
        }

        $response = $request->is(...self::OPEN) || $this->admits($request, $password)
            ? $next($request)
            : response('Staging is for the team only.', 401, [
                'WWW-Authenticate' => 'Basic realm="staging", charset="UTF-8"',
            ]);

        // On everything, including what the team was let into: a page that
        // leaks into search results once stays there.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function admits(Request $request, string $password): bool
    {
        // Both compared in constant time, and both always compared, so the
        // response time says nothing about which half was wrong.
        $user = hash_equals((string) config('clinic.staging_gate.user'), (string) $request->getUser());
        $pass = hash_equals($password, (string) $request->getPassword());

        return $user && $pass;
    }
}
