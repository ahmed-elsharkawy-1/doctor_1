<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "تأجيل مواعيد اليوم" — SPEC §4.5.
 *
 * Cancels the affected bookings — freeing their slots so they can actually be
 * rebooked — and hands the secretary a call list to work through. WhatsApp
 * template broadcasts are handled by the messaging service.
 */
class PostponeService
{
    public function __construct(private readonly QueueService $queue) {}

    /**
     * @param  list<int>|null  $bookingIds  null postpones everyone still pending
     * @return Collection<int, Booking>
     */
    public function postpone(Clinic $clinic, Carbon $date, ?array $bookingIds = null): Collection
    {
        $candidates = $this->queue->postponeCandidates($clinic, $date);

        if ($bookingIds !== null) {
            $candidates = $candidates->whereIn('id', $bookingIds)->values();
        }

        if ($candidates->isEmpty()) {
            throw ApiException::make(
                ApiErrorCode::NOTHING_TO_POSTPONE,
                __('booking.nothing_to_postpone'),
            );
        }

        $now = Carbon::now($clinic->timezone);

        DB::transaction(function () use ($clinic, $candidates, $now): void {
            $clinic->bookings()
                ->whereIn('id', $candidates->pluck('id'))
                ->update([
                    'status' => BookingStatus::CANCELLED,
                    'cancel_reason' => CancelReason::EMERGENCY,
                    'cancelled_at' => $now,
                    // A fresh worklist: nobody has been called yet.
                    'contacted_at' => null,
                    'updated_at' => $now,
                ]);
        });

        return $clinic->bookings()
            ->with(['patient', 'visitType'])
            ->whereIn('id', $candidates->pluck('id'))
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Ticks "تم الاتصال" so the secretary does not lose her place in a long
     * list. It does not rebook anyone.
     */
    public function markContacted(Booking $booking): Booking
    {
        $booking->update([
            'contacted_at' => Carbon::now($booking->clinic?->timezone ?? config('app.timezone')),
        ]);

        return $booking->refresh();
    }

    /**
     * Links a cancelled booking to the new appointment that replaced it, which
     * is what takes the patient off the rebooking list.
     */
    public function linkRebooking(Booking $original, Booking $replacement): void
    {
        $original->update(['rebooked_booking_id' => $replacement->id]);
    }
}
