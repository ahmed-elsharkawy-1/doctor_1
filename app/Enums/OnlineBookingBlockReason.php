<?php

namespace App\Enums;

/**
 * Why a patient may not book on the public page.
 */
enum OnlineBookingBlockReason: string
{
    /** Too many no-shows inside the window — set by the system. */
    case NO_SHOWS = 'no_shows';

    /** The clinic blocked them by hand. */
    case MANUAL = 'manual';

    public function label(): string
    {
        return __('patient.online_booking.reason.'.$this->value);
    }
}
