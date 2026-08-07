<?php

namespace App\Services\Results\V1\Patients;

use App\Models\Booking;
use App\Models\Patient;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * A patient's page: who she is, a summary, and every visit newest first.
 *
 * The phone is **unmasked** here — this screen has a call action.
 */
final class PatientHistoryResult extends ServiceResult
{
    /**
     * @param  Collection<int, Booking>  $history
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        private readonly Patient $patient,
        private readonly Collection $history,
        private readonly array $summary,
        private readonly bool $withPrice = false,
    ) {}

    public function toArray(): array
    {
        $phone = $this->patient->phone ? PhoneNumber::tryParse($this->patient->phone) : null;

        return [
            'patient' => [
                'id' => $this->patient->id,
                'code' => $this->patient->code,
                'name' => $this->patient->name,
                'phone' => Wire::phone($phone),
                'registered_at' => Wire::date($this->patient->created_at),
            ],
            'summary' => [
                'visits_count' => $this->summary['visits_count'],
                'no_show_count' => $this->summary['no_show_count'],
                'cancelled_count' => $this->summary['cancelled_count'],
                'first_visit' => $this->summary['first_visit'] === null
                    ? null
                    : Wire::date($this->summary['first_visit']->visit_date),
                'last_visit' => $this->summary['last_visit'] === null
                    ? null
                    : Wire::date($this->summary['last_visit']->visit_date),
            ],
            'visits' => $this->history
                ->map(fn (Booking $booking) => $this->visit($booking))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(Booking $booking): array
    {
        $body = [
            'booking_id' => $booking->id,
            'date' => Wire::date($booking->visit_date),
            'start_time' => Wire::time($booking->start_at),
            'visit_type' => [
                'id' => $booking->visit_type_id,
                'name' => $booking->visitType?->name,
                // The snapshot, so an old visit still reads as it was booked.
                'duration_minutes' => $booking->duration_minutes,
            ],
            'status' => Wire::enum($booking->status, $booking->status->label()),
            'cancel_reason' => $booking->cancel_reason === null
                ? null
                : Wire::enum($booking->cancel_reason, $booking->cancel_reason->label()),
            'notes' => $booking->notes,
        ];

        if ($this->withPrice) {
            $body['price'] = Wire::money($booking->price);
        }

        return $body;
    }
}
