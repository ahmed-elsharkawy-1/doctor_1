<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\QueueDisplay;
use App\Services\V1\Displays\QueueDisplayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * A waiting-room TV's board (ELA-19). No sign-in: the token in the address is
 * the whole of its access, as a patient's tracking link is. It shows patient
 * codes and first names only, as a waiting room may see.
 *
 * The page loads once and then fetches just the board every few seconds
 * (`?board=1`). A fetch that fails (a deploy, a dropped connection, a rate
 * limit) leaves the last board up and tries again: a TV left alone for
 * hours must never end up stuck on an error page.
 */
class QueueDisplayController extends Controller
{
    public function __invoke(Request $request, string $token, QueueDisplayService $displays): Response
    {
        App::setLocale(config('clinic.api.default_locale'));

        $display = QueueDisplay::query()->where('token', $token)->firstOrFail();

        abort_unless($display->clinic?->is_active, 404);

        $board = $displays->board($display) + [
            'refreshSeconds' => (int) config('clinic.displays.refresh_seconds'),
        ];

        $view = $request->boolean('board') ? 'tv.board' : 'tv.show';

        return response()->view($view, $board)->withHeaders([
            // The token is in the address: never sent on, cached or indexed.
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
