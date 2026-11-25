<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\QueueDisplay;
use App\Services\V1\Displays\QueueDisplayService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;

/**
 * A waiting-room TV's board (ELA-19). No sign-in: the token in the address is
 * the whole of its access, as a patient's tracking link is. It shows patient
 * codes and first names only, as a waiting room may see.
 *
 * The page reloads itself rather than holding a connection open: a TV's
 * browser is left alone for hours, and a full reload recovers from anything.
 */
class QueueDisplayController extends Controller
{
    public function __invoke(string $token, QueueDisplayService $displays): View
    {
        App::setLocale(config('clinic.api.default_locale'));

        $display = QueueDisplay::query()->where('token', $token)->firstOrFail();

        abort_unless($display->clinic?->is_active, 404);

        return view('tv.show', $displays->board($display) + [
            'refreshSeconds' => (int) config('clinic.displays.refresh_seconds'),
        ]);
    }
}
