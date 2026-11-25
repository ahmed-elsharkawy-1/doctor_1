<?php

namespace App\Livewire\App;

use App\Enums\WhatsAppOptOutSource;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Patient;
use App\Services\Results\V1\Patients\OnlineBookingStatusResult;
use App\Services\Results\V1\Patients\WhatsAppConsentResult;
use App\Services\V1\Patients\OnlineBookingService;
use App\Services\V1\Patients\PatientSearchService;
use App\Services\V1\Patients\WhatsAppConsentService;
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

    /** The outcome of the last action, shown once. */
    public ?string $notice = null;

    public bool $failed = false;

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
            'showBranches' => $this->clinic()->branches()->count() > 1,
            'summary' => app(PatientSearchService::class)->summary($history),
            'canSeePrices' => auth()->user()?->hasAbility('prices.view') ?? false,
            // The same reading the API returns, so the two never disagree.
            'onlineBooking' => (new OnlineBookingStatusResult($patient, app(OnlineBookingService::class)))->toArray(),
            'canSwitchOnlineBooking' => auth()->user()?->hasAbility('bookings.manage') ?? false,
            'whatsApp' => (new WhatsAppConsentResult($patient))->toArray(),
        ])->title($patient->name);
    }

    /** Stops every WhatsApp message to the patient, until turned back on here. */
    public function stopWhatsApp(): void
    {
        $this->requireAbility('bookings.manage');

        app(WhatsAppConsentService::class)->stop($this->patient(), WhatsAppOptOutSource::CLINIC);

        $this->notice = __('patient.whatsapp.stopped_ok');
        $this->failed = false;
    }

    public function resumeWhatsApp(): void
    {
        $this->requireAbility('bookings.manage');

        app(WhatsAppConsentService::class)->resume($this->patient());

        $this->notice = __('patient.whatsapp.resumed_ok');
        $this->failed = false;
    }

    /** Lifts a block. No-shows before now stop counting. */
    public function allowOnlineBooking(): void
    {
        $this->requireAbility('bookings.manage');

        app(OnlineBookingService::class)->allow($this->patient());

        $this->notice = __('patient.online_booking.allowed_ok');
        $this->failed = false;
    }

    /** Blocks by hand — applies whether the clinic's automatic rule is on or not. */
    public function blockOnlineBooking(): void
    {
        $this->requireAbility('bookings.manage');

        app(OnlineBookingService::class)->block($this->patient());

        $this->notice = __('patient.online_booking.blocked_ok');
        $this->failed = false;
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
