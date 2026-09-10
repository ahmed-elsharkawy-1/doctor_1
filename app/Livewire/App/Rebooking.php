<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Queue\PostponeService;
use App\Services\V1\Queue\QueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * The call list: everyone still owed a new appointment after a postponement.
 *
 * A patient leaves this list only when a replacement booking is linked to the
 * original, which happens inside BookingService when the new booking carries
 * `rebookingForBookingId`. Ticking "تم الاتصال" does not rebook anyone — it
 * only keeps the secretary's place in a long list.
 */
class Rebooking extends ClinicComponent
{
    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        $this->requireAbility('queue.manage');
    }

    public function render(): View
    {
        return view('livewire.app.rebooking', [
            'bookings' => $this->bookings(),
        ])->title(__('app.rebooking.title'));
    }

    public function markContacted(int $bookingId): void
    {
        $this->requireAbility('queue.manage');

        try {
            $booking = app(BookingService::class)->find($this->clinic(), $bookingId);

            app(PostponeService::class)->markContacted($booking);
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->notice = __('booking.marked_contacted');
        $this->failed = false;
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookings(): Collection
    {
        $this->requireAbility('queue.manage');

        return app(QueueService::class)->awaitingRebooking($this->clinic());
    }
}
