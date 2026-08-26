<?php

namespace App\Enums;

enum PatientLocation: string
{
    case INSIDE_CLINIC = 'inside_clinic';
    case ON_WAY = 'on_way';

    public function label(): string
    {
        return __('booking.patient_location.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
