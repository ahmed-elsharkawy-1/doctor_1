<?php

namespace App\Enums;

/**
 * Which door a booking came through.
 *
 * A second dimension beside BookingStatus, in the same way BookingKind is —
 * never a status of its own. A self-booked visit is `booked` like any other:
 * it holds its slot, it sits in the queue, it counts everywhere.
 *
 * Nothing may consume this in a `match` without a `default` arm. That is the
 * rule BookingStatus broke, and adding a case there would throw on the queue
 * screen; keep this one safe to extend.
 *
 * It is never accepted from a client. BookingData carries it as a constructor
 * default and `fromArray()` deliberately ignores it, so no API caller can
 * label their own booking as a patient's.
 */
enum BookingSource: string
{
    /** Taken by staff — the mobile app or the clinic web app. */
    case CLINIC = 'clinic';

    /** Taken by the patient themselves on the public booking page. */
    case PATIENT_WEB = 'patient_web';

    public function label(): string
    {
        return __('booking.source.'.$this->value);
    }

    /** Whether a booking from here is worth flagging to the clinic. */
    public function needsClinicAttention(): bool
    {
        return $this === self::PATIENT_WEB;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> value => label, for Filament selects
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
