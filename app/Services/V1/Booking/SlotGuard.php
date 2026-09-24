<?php

namespace App\Services\V1\Booking;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\VisitType;
use Illuminate\Support\Carbon;

/**
 * "Is this exact time still free?" — asked identically by everything that
 * claims a slot.
 *
 * Lifted out of BookingService when slot holds arrived. A hold and a booking
 * are both claims on the same minutes, so they have to answer this question
 * the same way: if holding were more permissive, a screen would offer a time
 * the write path then refused; if it were stricter, a patient would be blocked
 * from booking the slot they are sitting on.
 *
 * Always call this inside ClinicDayLock::claim(), never before it. Availability
 * read outside the lock is a guess by the time it is acted on.
 */
final class SlotGuard
{
    public function __construct(private readonly SlotAvailabilityService $slots) {}

    /**
     * @param  int|null  $ignoreBookingId  the booking being edited, which must
     *                                     not collide with itself
     * @param  string|null  $holdToken  the caller's own hold, which blocks
     *                                  everyone except the caller
     *
     * @throws ApiException unless the slot is free
     */
    public function ensureFree(
        Clinic $clinic,
        Carbon $startAt,
        VisitType $visitType,
        ?int $ignoreBookingId = null,
        ?string $holdToken = null,
    ): void {
        $availability = $this->slots->for(
            $clinic,
            $startAt->copy()->startOfDay(),
            $visitType,
            $ignoreBookingId,
            $holdToken,
        );

        if (! $availability->isOpen) {
            throw ApiException::make(
                match ($availability->closedReason) {
                    ClosedReason::OUTSIDE_WINDOW => ApiErrorCode::SLOT_OUTSIDE_WINDOW,
                    default => ApiErrorCode::CLINIC_CLOSED_THAT_DAY,
                },
                $availability->closedReason?->label() ?? __('booking.clinic_closed'),
                details: ['reason' => $availability->closedReason?->value],
                http: 409,
            );
        }

        foreach ($availability->slots as $slot) {
            if ($slot->startAt->equalTo($startAt)) {
                if ($slot->isAvailable) {
                    return;
                }

                throw ApiException::make(
                    ApiErrorCode::SLOT_UNAVAILABLE,
                    __('booking.slot_unavailable'),
                    details: ['start_time' => $startAt->format('H:i')],
                    http: 409,
                );
            }
        }

        // A time the clinic never offers for this visit type — off-grid, or
        // the visit would not finish before the period ends.
        throw ApiException::make(
            ApiErrorCode::SLOT_OUTSIDE_WORKING_HOURS,
            __('booking.slot_outside_hours'),
            details: ['start_time' => $startAt->format('H:i')],
            http: 409,
        );
    }
}
