<?php

namespace App\Enums;

enum BookingKind: string
{
    case NORMAL = 'normal';
    case EMERGENCY = 'emergency';

    public function label(): string
    {
        return __('booking.kind.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
