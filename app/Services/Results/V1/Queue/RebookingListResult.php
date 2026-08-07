<?php

namespace App\Services\Results\V1\Queue;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * The call list — the secretary's worklist after a postponement (SPEC §4.5).
 *
 * Phones are unmasked here: every row has a call action.
 */
final class RebookingListResult extends ServiceResult
{
    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function __construct(private readonly Collection $bookings) {}

    public function toArray(): array
    {
        return [
            'items' => $this->bookings
                ->map(function (Booking $booking): array {
                    $patient = $booking->patient;
                    $phone = $patient?->phone ? PhoneNumber::tryParse($patient->phone) : null;

                    return [
                        'booking_id' => $booking->id,
                        'patient' => $patient === null ? null : [
                            'id' => $patient->id,
                            'code' => $patient->code,
                            'name' => $patient->name,
                            'phone' => Wire::phone($phone),
                        ],
                        'visit_type' => [
                            'id' => $booking->visit_type_id,
                            'name' => $booking->visitType?->name,
                        ],
                        // What she was booked for, so the secretary can offer
                        // a like-for-like replacement.
                        'original_date' => Wire::date($booking->visit_date),
                        'original_start_time' => Wire::time($booking->start_at),
                        'contacted' => $booking->contacted_at !== null,
                        'contacted_at' => $booking->contacted_at?->toAtomString(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
