<?php

namespace App\Enums;

/**
 * The patient's answer to the appointment reminder.
 *
 * Null means no answer. `confirmed` leaves the booking `booked`; `cancelled`
 * is always accompanied by `BookingStatus::CANCELLED`. There is deliberately
 * no `none` case — null is how "no answer yet" is stored.
 */
enum PatientResponse: string
{
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return __('booking.patient_response.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
