<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps non-production copies of the app out of search results, and can put
 * a password in front of them.
 *
 * Staging is the whole app on a public address. A search engine is the one
 * realistic way a patient would find it, so outside production every
 * response asks not to be indexed. Production is left exactly as it was.
 *
 * The password is optional and off by default — the team found it more
 * friction than protection. Set STAGING_GATE_PASSWORD to turn it on. Even
 * then the API (a staging build of the mobile app uses it), Meta's webhook
 * (signed) and the container health check stay open: each has its own
 * authentication and none can answer a browser's password prompt.
 */
class StagingGate
{
    private const OPEN = ['api/*', 'webhooks/*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('clinic.staging_gate.password');

        $response = $password === '' || $request->is(...self::OPEN) || $this->admits($request, $password)
            ? $next($request)
            : response('Staging is for the team only.', 401, [
                'WWW-Authenticate' => 'Basic realm="staging", charset="UTF-8"',
            ]);

        // On everything, including what the team was let into: a page that
        // leaks into search results once stays there.
        if (! app()->isProduction() || $password !== '') {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

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
