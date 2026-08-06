<?php

namespace App\Http\Requests\Api\V1\Booking;

class UpdateBookingRequest extends StoreBookingRequest
{
    // Same body as create. Editing a booking re-runs the full availability
    // check, so a partial payload would leave the slot ambiguous.
}
