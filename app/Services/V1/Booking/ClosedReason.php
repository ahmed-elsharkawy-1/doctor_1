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

    /**
     * @param  bool  $atBranch  the doctor has several branches: a weekly
     *                          closure is this branch's, not the clinic's
     */
    public function label(bool $atBranch = false): string
    {
        if ($atBranch && $this === self::WEEKLY_CLOSED) {
            return __('booking.closed_reason.branch_weekly_closed');
        }

        return __('booking.closed_reason.'.$this->value);
    }
}
