<?php

namespace App\Livewire\App;

use App\Models\Branch;
use App\Models\Clinic;
use App\Services\V1\Branches\ActiveBranch;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Throwable;

/**
 * Base for every screen in the clinic web app.
 *
 * The clinic is resolved from the signed-in account on each request rather
 * than read off the request attributes, because Livewire's own update route
 * does not pass through this app's route middleware — and because it keeps
 * the rule from the API intact: `clinic_id` is never taken from the client.
 *
 * Components orchestrate and render. Every business rule stays in the
 * services, which are the same ones the mobile API calls.
 */
abstract class ClinicComponent extends Component
{
    public function clinic(): Clinic
    {
        $user = auth()->user();

        abort_if($user === null || ! $user->is_active || ! $user->role->usesMobileApp(), 403);

        $clinic = $user->activeClinic();

        // The session went away mid-visit, the account was detached, or the
        // clinic was disabled after the component first loaded.
        abort_if($clinic === null || ! $clinic->is_active, 403);

        return $clinic;
    }

    /**
     * The branch this screen works on (ELA-17): the one picked in this
     * browser if the account may still use it, else as ActiveBranch decides.
     * Resolved per request for the same reason as the clinic.
     */
    public function branch(): Branch
    {
        $clinic = $this->clinic();
        $requested = session(ActiveBranch::SESSION_KEY);
        $branch = app(ActiveBranch::class)->resolve(
            auth()->user(),
            $clinic,
            is_numeric($requested) ? (int) $requested : null,
        );

        abort_if($branch === null, 403);

        return $branch;
    }

    /**
     * Refuses a screen the signed-in account has no business seeing.
     *
     * Mirrors the API's EnsureAbility middleware. Today UserRole::CLINIC holds
     * every ability, so nothing is hidden — the checks exist so that stops
     * being true safely rather than silently.
     */
    protected function requireAbility(string $ability): void
    {
        abort_unless(auth()->user()?->hasAbility($ability) ?? false, 403);
    }

    /**
     * A date the screen can safely work with.
     *
     * Screens keep their date as a string, and on the queue it is bound to the
     * query string — so it is whatever the address bar happens to contain.
     * Anything unparseable falls back to today rather than throwing.
     */
    protected function safeDate(?string $date): Carbon
    {
        $clinic = $this->clinic();

        if (blank($date)) {
            return $clinic->currentDay();
        }

        try {
            return Carbon::parse($date, $clinic->timezone)->startOfDay();
        } catch (Throwable) {
            return $clinic->currentDay();
        }
    }
}
