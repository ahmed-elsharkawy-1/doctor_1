<?php

namespace App\Services\V1\Booking;

/**
 * Why a day has no slots. The app shows a different empty state for each.
 */
enum ClosedReason: string
{
    /** Not a working day in the weekly schedule. */
    case WEEKLY_CLOSED = 'weekly_closed';

    /** A one-off holiday overriding an otherwise open day. */
    case HOLIDAY = 'holiday';

    /** Outside the rolling booking window, or in the past. */
    case OUTSIDE_WINDOW = 'outside_window';

    /** The branch was deactivated: no new bookings there (ELA-16). */
    case BRANCH_INACTIVE = 'branch_inactive';

    public function label(): string
    {
        return __('booking.closed_reason.'.$this->value);
    }
}
