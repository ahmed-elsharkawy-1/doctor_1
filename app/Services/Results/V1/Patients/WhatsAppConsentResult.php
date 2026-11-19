<?php

namespace App\Services\Results\V1\Patients;

use App\Models\Patient;
use App\Services\Results\ServiceResult;
use App\Support\Wire;

/**
 * Whether a patient gets WhatsApp messages, and if stopped, since when and
 * by whom — on the patient's page, and back from the switch.
 */
final class WhatsAppConsentResult extends ServiceResult
{
    public function __construct(private readonly Patient $patient) {}

    public function toArray(): array
    {
        $source = $this->patient->whatsapp_opt_out_source;

        return [
            'enabled' => $this->patient->whatsapp_opt_in_at !== null,
            // Stopped on purpose, as opposed to never having agreed.
            'stopped' => $this->patient->whatsapp_opted_out_at !== null,
            'stopped_at' => $this->patient->whatsapp_opted_out_at === null
                ? null
                : Wire::dateTime($this->patient->whatsapp_opted_out_at),
            'stopped_by' => $source === null ? null : Wire::enum($source, $source->label()),
        ];
    }
}
