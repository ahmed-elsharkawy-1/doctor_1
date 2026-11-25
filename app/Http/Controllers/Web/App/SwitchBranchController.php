<?php

namespace App\Http\Controllers\Web\App;

use App\Http\Controllers\Controller;
use App\Services\V1\Branches\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The header's branch switcher (ELA-17). One tap: the branch becomes this
 * browser's, and the one the apps open on next time.
 *
 * Back to the same screen without its query string, so a day or filter
 * picked for the old branch doesn't carry over to the new one.
 */
class SwitchBranchController extends Controller
{
    public function __invoke(Request $request, ActiveBranch $branches): RedirectResponse
    {
        $wanted = (int) $request->input('branch');
        $user = $request->user();
        $branch = $branches->resolve($user, $request->attributes->get('clinic'), $wanted);

        // Not one this account may use: stay where it is.
        if ($branch !== null && $branch->id === $wanted) {
            $request->session()->put(ActiveBranch::SESSION_KEY, $branch->id);
            $branches->remember($user, $branch);
        }

        $back = parse_url(url()->previous(), PHP_URL_PATH) ?: '/';

        $insideApp = $back === '/app' || str_starts_with($back, '/app/');

        return redirect()->to($insideApp ? $back : route('app.queue'));
    }
}
