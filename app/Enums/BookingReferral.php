<?php

namespace App\Enums;

/**
 * What brought a patient to the booking page, where we can tell.
 *
 * A third dimension beside BookingStatus and BookingSource. `source` is who
 * made the booking; this is how the patient found the clinic. Null on a
 * booking means nothing we can name.
 *
 * Never accepted from a client: it is read from the patient's own session,
 * which only the server writes. Nothing may consume this in a `match` without
 * a `default` arm, so new channels can be added safely.
 */
enum BookingReferral: string
{
    /** Opened the clinic's page from its printed QR poster. */
    case QR = 'qr';

    public function label(): string
    {
        return __('booking.referral.'.$this->value);
    }
}
