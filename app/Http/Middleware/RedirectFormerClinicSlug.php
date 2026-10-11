<?php

namespace App\Http\Middleware;

use App\Services\V1\Clinic\ClinicSlugService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an address a clinic used before to the one it uses now, with the
 * query string intact, so a scan from an old poster is still counted as one.
 *
 * A 302, not a 301: browsers keep a 301 for good, and a slug change can be
 * undone. A phone that remembered "old → new" would then loop forever once
 * the clinic took its old address back and "new → old" became the redirect.
 */
class RedirectFormerClinicSlug
{
    public function __construct(private readonly ClinicSlugService $slugs) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('slug');
        $current = $this->slugs->currentSlugFor($slug);

        if ($current === null) {
            return $next($request);
        }

        $path = preg_replace('#^'.preg_quote($slug, '#').'(?=/|$)#', $current, $request->path());
        $query = $request->getQueryString();

        return redirect()->to(url($path).($query ? '?'.$query : ''), 302);
    }
}
