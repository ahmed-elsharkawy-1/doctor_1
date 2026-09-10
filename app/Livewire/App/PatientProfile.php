<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Patient;
use App\Services\V1\Patients\PatientSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * One patient: who they are, and every visit they have had.
 *
 * History deliberately includes cancellations and no-shows — a pattern of
 * them is exactly what the secretary needs to see before booking again.
 */
class PatientProfile extends ClinicComponent
{
    public int $patientId;

    public function mount(int $patient): void
    {
        $this->requireAbility('patients.view');

        $this->patientId = $patient;

        // Resolved here so another clinic's id is a 404 on arrival rather
        // than an empty page.
        $this->patient();
    }

    public function render(): View
    {
        $patient = $this->patient();
        $history = $this->history($patient);

        return view('livewire.app.patient-profile', [
            'patient' => $patient,
            'history' => $history,
            'summary' => app(PatientSearchService::class)->summary($history),
            'canSeePrices' => auth()->user()?->hasAbility('prices.view') ?? false,
        ])->title($patient->name);
    }

    /**
     * Scoped through the service, so another clinic's patient is a 404 here
     * exactly as it is on the API.
     */
    private function patient(): Patient
    {
        try {
            return app(PatientSearchService::class)->find($this->clinic(), $this->patientId);
        } catch (ApiException) {
            abort(404);
        }
    }

    /**
     * @return Collection<int, Booking>
     */
    private function history(Patient $patient): Collection
    {
        return app(PatientSearchService::class)->history($patient);
    }
}
