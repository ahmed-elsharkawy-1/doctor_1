<?php

namespace App\Services\Results\V1\Booking;

use App\Models\Booking;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * A booking on the wire. Price appears only for callers with `prices.view`.
 */
final class BookingResult extends ServiceResult
{
    public function __construct(
        private readonly Booking $booking,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        $booking = $this->booking;
        $patient = $booking->patient;
        $phone = $patient?->phone ? PhoneNumber::tryParse($patient->phone) : null;

        $body = [
            'id' => $booking->id,
            'status' => Wire::enum($booking->status, $booking->status->label()),
            'cancel_reason' => $booking->cancel_reason === null
                ? null
                : Wire::enum($booking->cancel_reason, $booking->cancel_reason->label()),
            'patient' => $patient === null ? null : [
                'id' => $patient->id,
                'code' => $patient->code,
                'name' => $patient->name,
                // Full number: the queue card offers a call action.
                'phone' => Wire::phone($phone),
            ],
            'visit_type' => [
                'id' => $booking->visit_type_id,
                'name' => $booking->visitType?->name,
                // The snapshot, not the visit type's current value.
                'duration_minutes' => $booking->duration_minutes,
            ],
            'date' => Wire::date($booking->visit_date),
            'start_time' => Wire::time($booking->start_at),
            'end_time' => Wire::time($booking->end_at),
            'is_overbooked' => $booking->is_overbooked,
            'notes' => $booking->notes,
        ];

        if ($this->withPrice) {
            $body['price'] = Wire::money($booking->price);
        }

        return $body;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $bookings, bool $withPrice = false): array
    {
        return $bookings
            ->map(fn (Booking $booking) => (new self($booking, $withPrice))->toArray())
            ->values()
            ->all();
    }
}
