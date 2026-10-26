<?php

namespace App\Services\Results\V1\Reviews;

use App\Enums\DayOfWeek;
use App\Models\BookingReview;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * One review, with everything its details sheet shows — so the sheet opens
 * from the list without another request.
 *
 * The comment is whole; the list shortens it on screen. The phone is
 * unmasked: the sheet has call and WhatsApp buttons.
 */
final class ReviewListItemResult extends ServiceResult
{
    public function __construct(private readonly BookingReview $review) {}

    public function toArray(): array
    {
        $patient = $this->review->patient;
        $booking = $this->review->booking;
        $phone = $patient?->phone ? PhoneNumber::tryParse($patient->phone) : null;
        $comment = trim((string) $this->review->comment);

        return [
            'id' => $this->review->id,
            'rating' => Wire::enum($this->review->rating, $this->review->rating->staffLabel()),
            'comment' => $comment === '' ? null : $comment,
            'submitted_at' => $this->review->submitted_at->toAtomString(),
            'patient' => $patient === null ? null : [
                'id' => $patient->id,
                'code' => $patient->code,
                'name' => $patient->name,
                'phone' => Wire::phone($phone),
            ],
            'visit' => $booking === null ? null : [
                'booking_id' => $booking->id,
                'date' => Wire::date($booking->visit_date),
                'day' => DayOfWeek::fromDate($booking->visit_date)->label(),
                'start_time' => $booking->start_at === null ? null : Wire::time($booking->start_at),
                'after_midnight' => $booking->startsAfterMidnight(),
                'visit_type' => [
                    'id' => $booking->visit_type_id,
                    'name' => $booking->visitType?->name,
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, BookingReview>  $reviews
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $reviews): array
    {
        return $reviews
            ->map(fn (BookingReview $review) => (new self($review))->toArray())
            ->values()
            ->all();
    }
}
