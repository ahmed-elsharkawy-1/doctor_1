<?php

namespace App\Livewire\App\Settings;

use App\DTOs\V1\Settings\GeneralSettingsData;
use App\Services\V1\Settings\ClinicSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

/**
 * The three numbers that decide how far ahead patients may book and how early
 * they are asked to arrive.
 */
class General extends SettingsComponent
{
    public int $bookingWindowDays = 7;

    public int $firstVisitOnlyDays = 60;

    public int $patientArrivalLeadMinutes = 30;

    public function mount(): void
    {
        parent::mount();

        $clinic = $this->clinic();

        $this->bookingWindowDays = $clinic->booking_window_days;
        $this->firstVisitOnlyDays = $clinic->first_visit_only_days;
        $this->patientArrivalLeadMinutes = $clinic->patient_arrival_lead_minutes;
    }

    public function render(): View
    {
        return view('livewire.app.settings.general', [
            'leadOptions' => config('clinic.settings.patient_arrival_lead_minute_options'),
        ])->title(__('app.settings.general'));
    }

    public function save(): void
    {
        $this->validate();

        $this->run(function (): void {
            app(ClinicSettingsService::class)->updateGeneral(
                $this->clinic(),
                GeneralSettingsData::fromArray([
                    'booking_window_days' => $this->bookingWindowDays,
                    'first_visit_only_days' => $this->firstVisitOnlyDays,
                    'patient_arrival_lead_minutes' => $this->patientArrivalLeadMinutes,
                ]),
            );
        }, __('app.settings.saved'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The same bounds the API enforces.
        return [
            'bookingWindowDays' => ['required', 'integer', 'min:1', 'max:90'],
            'firstVisitOnlyDays' => ['required', 'integer', 'min:1', 'max:730'],
            'patientArrivalLeadMinutes' => [
                'required',
                'integer',
                Rule::in(config('clinic.settings.patient_arrival_lead_minute_options')),
            ],
        ];
    }
}
