<?php

namespace App\Livewire\App;

use App\Models\Clinic;
use Livewire\Component;

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
        $clinic = auth()->user()?->activeClinic();

        // The session went away mid-visit, or the account was detached.
        abort_if($clinic === null || ! $clinic->is_active, 403);

        return $clinic;
    }
}
