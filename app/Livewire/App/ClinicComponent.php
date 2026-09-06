<?php

namespace App\Livewire\App;

use App\Models\Clinic;
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
     * A date the screen can safely work with.
     *
     * Screens keep their date as a string, and on the queue it is bound to the
     * query string — so it is whatever the address bar happens to contain.
     * Anything unparseable falls back to today rather than throwing.
     */
    protected function safeDate(?string $date): Carbon
    {
        $timezone = $this->clinic()->timezone;

        try {
            return Carbon::parse($date ?: 'now', $timezone)->startOfDay();
        } catch (Throwable) {
            return Carbon::now($timezone)->startOfDay();
        }
    }
}
