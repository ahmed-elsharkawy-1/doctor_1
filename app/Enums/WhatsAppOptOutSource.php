<?php

namespace App\Enums;

/**
 * Who stopped a patient's WhatsApp messages.
 */
enum WhatsAppOptOutSource: string
{
    /** The doctor or secretary, from the app. */
    case CLINIC = 'clinic';

    /** The patient, by replying «إيقاف» on WhatsApp. */
    case PATIENT = 'patient';

    public function label(): string
    {
        return __('patient.whatsapp.source.'.$this->value);
    }
}
