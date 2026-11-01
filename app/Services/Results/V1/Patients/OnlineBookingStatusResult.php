<?php

namespace App\Services\Results\V1\Patients;

use App\Models\Patient;
use App\Services\Results\ServiceResult;
use App\Services\V1\Patients\OnlineBookingService;
use App\Support\Wire;

/**
 * Whether a patient may book on the public page, and how close the no-show
 * rule is to blocking them — on the patient's page, and back from the switch.
 */
final class OnlineBookingStatusResult extends ServiceResult
{
    public function __construct(
        private readonly Patient $patient,
        private readonly OnlineBookingService $onlineBooking,
    ) {}

    public function toArray(): array
    {
        $reason = $this->patient->online_booking_block_reason;

        return [
            'allowed' => ! $this->onlineBooking->isBlocked($this->patient),
            'blocked_at' => $this->patient->online_booking_blocked_at === null
                ? null
                : Wire::dateTime($this->patient->online_booking_blocked_at),
            'reason' => $reason === null ? null : Wire::enum($reason, $reason->label()),
            // What the rule is counting now: inside the window, and after the
            // clinic last lifted a block.
            'counted_no_shows' => $this->onlineBooking->countedNoShows($this->patient),
            'threshold' => $this->onlineBooking->threshold(),
            'window_days' => $this->onlineBooking->windowDays(),
            // Whether no-shows block automatically at this clinic. A block set
            // by hand applies either way.
            'rule_enabled' => (bool) $this->patient->clinic->no_show_block_enabled,
        ];
    }
}
