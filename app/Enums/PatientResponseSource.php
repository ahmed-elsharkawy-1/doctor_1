<?php

namespace App\Enums;

/**
 * How the patient's answer reached the clinic.
 *
 * `whatsapp` is the patient tapping a button on the visit-day reminder.
 * `clinic` is the secretary recording it by hand after calling a patient who
 * never answered the reminder. Null only beside a null `patient_response`.
 */
enum PatientResponseSource: string
{
    case WHATSAPP = 'whatsapp';
    case CLINIC = 'clinic';

    public function label(): string
    {
        return __('booking.patient_response_source.'.$this->value);
    }
}
