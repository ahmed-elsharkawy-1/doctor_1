<?php

namespace App\Services\Results\V1\Booking;

use App\Services\Results\ServiceResult;
use App\Services\V1\Booking\DayAvailability;
use App\Services\V1\Booking\Slot;
use App\Support\Wire;

final class SlotAvailabilityResult extends ServiceResult
{
    public function __construct(private readonly DayAvailability $availability) {}

    public function toArray(): array
    {
        $availability = $this->availability;

        return [
            'date' => Wire::date($availability->date),
            'is_open' => $availability->isOpen,
            'closed_reason' => $availability->closedReason === null ? null : Wire::enum(
                $availability->closedReason,
                $availability->closedReason->label(),
            ),
            'visit_type' => [
                'id' => $availability->visitType->id,
                'name' => $availability->visitType->name,
                'duration_minutes' => $availability->visitType->duration_minutes,
            ],
            'available_count' => $availability->availableCount(),
            // The full list, taken slots included — the UI greys and strikes
            // them through rather than hiding them (SPEC §5.1).
            'slots' => array_map(
                static fn (Slot $slot): array => [
                    'start_time' => Wire::time($slot->startAt),
                    'end_time' => Wire::time($slot->endAt),
                    'is_available' => $slot->isAvailable,
                ],
                $availability->slots,
            ),
        ];
    }
}
