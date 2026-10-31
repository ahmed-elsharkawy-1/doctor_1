<?php

namespace App\Services\V1\Queue;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Clinic;
use App\Services\V1\Patients\PatientSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds a patient in today's queue and says where they stand — for the
 * secretary answering "دوري امتى؟" on the phone, and typed as she goes.
 *
 * The numbers are the tracking page's own (QueuePositionService), so what she
 * reads out is what the patient is looking at on their phone.
 */
class QueueSearchService
{
    public function __construct(
        private readonly QueuePositionService $positions,
        private readonly PatientSearchService $patients,
    ) {}

    /**
     * Matches first in line first. Nobody outside today's queue is returned:
     * a finished, cancelled or no-show visit has no place to report.
     *
     * @return array{queue: Collection<int, Booking>, matches: Collection<int, array{booking: Booking, position: QueuePosition}>}
     */
    public function search(Clinic $clinic, string $term, int $limit): array
    {
        $term = trim($term);

        $ids = $clinic->bookings()
            ->onDate($clinic->currentDay()->toDateString())
            ->whereIn('status', BookingStatus::inQueue())
            ->whereHas('patient', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $this->patients->applyTerm($inner, $term),
            ))
            ->pluck('id');

        // Read after the match, and once: every position below comes from
        // this one copy of the day, so no two rows can disagree.
        $queue = $this->positions->today($clinic);

        $matches = $queue
            ->whereIn('id', $ids)
            ->map(fn (Booking $booking): array => [
                'booking' => $booking,
                'position' => $this->positions->within($clinic, $queue, $booking),
            ])
            ->sortBy(fn (array $match): int => $match['position']->ahead)
            ->take($limit)
            ->values();

        return ['queue' => $queue, 'matches' => $matches];
    }
}
