<?php

namespace App\Services\Results\V1\Settings;

use App\Models\Clinic;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;

/**
 * The clinic's own configuration, shared by /bootstrap and
 * /settings/general.
 */
final class ClinicSettingsResult extends ServiceResult
{
    public function __construct(private readonly Clinic $clinic) {}

    public function toArray(): array
    {
        $phone = $this->clinic->phone ? PhoneNumber::tryParse($this->clinic->phone, $this->clinic->country_code) : null;

        return [
            'id' => $this->clinic->id,
            'name' => $this->clinic->name,
            'address' => $this->clinic->address,
            'phone' => Wire::phone($phone),
            'specialty' => $this->clinic->specialty?->name,
            'timezone' => $this->clinic->timezone,
            'country_code' => $this->clinic->country_code,
            'booking_window_days' => $this->clinic->booking_window_days,
            'first_visit_only_days' => $this->clinic->first_visit_only_days,
            'slot_step_minutes' => $this->clinic->slot_step_minutes,
            'patient_arrival_lead_minutes' => $this->clinic->patient_arrival_lead_minutes,
            'patient_arrival_lead_minute_options' => config('clinic.settings.patient_arrival_lead_minute_options'),
        ];
    }
}
