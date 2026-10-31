<?php

namespace App\Livewire\App;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Queue\BookingStatusService;
use App\Services\V1\Queue\QueuePosition;
use App\Services\V1\Queue\QueueSearchService;
use App\Services\V1\Queue\QueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * The clinic's working screen: today's patients, in queue order, with the
 * status taps that drive both this list and the patient's tracking page.
 *
 * Every action delegates to BookingStatusService — the same service behind
 * the mobile endpoints. Nothing here decides what a valid transition is.
 */
class Queue extends ClinicComponent
{
    #[Url(as: 'date', except: '')]
    public string $date = '';

    public ?int $cancelling = null;

    /**
     * "دوري امتى؟" on the phone: a patient looked up in today's queue.
     */
    public string $search = '';

    /**
     * The outcome of the last action. A public property rather than a flash:
     * a flash would survive into the next full request and be shown twice.
     */
    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        // `date` is bound to the query string, so it arrives as whatever the
        // address bar contained.
        $this->date = $this->safeDate($this->date)->toDateString();
    }

    public function render(): View
    {
        $bookings = $this->bookings();

        return view('livewire.app.queue', [
            'bookings' => $bookings,
            'counts' => $this->counts($bookings),
            'cancelReasons' => app(QueueService::class)->selectableCancelReasons(),
            'isToday' => $this->isToday(),
            'matches' => $this->matches(),
            'searching' => $this->searchTerm() !== null,
        ])->title(__('app.queue.title'));
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function goToDay(int $offset): void
    {
        $this->date = $this->safeDate($this->date)
            ->addDays($offset)
            ->toDateString();
    }

    public function today(): void
    {
        $this->date = $this->clinic()->currentDay()->toDateString();
    }

    public function arrive(int $bookingId): void
    {
        $this->move($bookingId, BookingStatus::ARRIVED);
    }

    public function callIn(int $bookingId): void
    {
        $this->move($bookingId, BookingStatus::WITH_DOCTOR);
    }

    public function complete(int $bookingId): void
    {
        $this->move($bookingId, BookingStatus::DONE);
    }

    public function noShow(int $bookingId): void
    {
        $this->move($bookingId, BookingStatus::NO_SHOW);
    }

    public function confirmCancel(int $bookingId): void
    {
        $this->cancelling = $bookingId;
    }

    public function dismissCancel(): void
    {
        $this->cancelling = null;
    }

    public function cancel(int $bookingId, string $reason): void
    {
        $this->cancelling = null;

        $this->run(function () use ($bookingId, $reason): void {
            app(BookingStatusService::class)->cancel(
                $this->find($bookingId),
                CancelReason::from($reason),
            );
        }, __('booking.cancelled_ok'));
    }

    private function move(int $bookingId, BookingStatus $target): void
    {
        $this->run(function () use ($bookingId, $target): void {
            app(BookingStatusService::class)->update($this->find($bookingId), $target);
        }, __('booking.status_updated'));
    }

    /**
     * Scoped through BookingService, so another clinic's booking id is a 404
     * here exactly as it is on the API.
     */
    private function find(int $bookingId): Booking
    {
        return app(BookingService::class)->find($this->clinic(), $bookingId);
    }

    /**
     * ApiException renders itself as a JSON envelope, which would break a
     * Livewire response. Caught here and shown as a flash instead; the message
     * is already translated.
     */
    /**
     * A patient booked themselves while she was on this screen.
     *
     * Re-rendering is what puts the new card in the list — the queue is read
     * fresh on every render, so there is nothing to merge by hand. The notice
     * is so she notices it arriving rather than finding it later.
     */
    #[On('self-booking-received')]
    public function selfBookingReceived(?string $name = null): void
    {
        $this->notice = __('app.queue.self_booking_arrived', [
            'name' => $name ?: __('app.queue.a_patient'),
        ]);
        $this->failed = false;
    }

    /**
     * A patient tapped Confirm or Cancel in WhatsApp.
     *
     * The card is re-read on render, so the reminder indicator and the
     * patient-response badge change without anybody touching the screen. A
     * cancellation is the one that needs a human: the slot is already free,
     * and this is how the secretary learns it happened.
     */
    #[On('reminder-responded')]
    public function reminderResponded(?string $name = null, ?string $response = null): void
    {
        $this->notice = __(
            $response === 'cancelled'
                ? 'app.queue.reminder_cancelled'
                : 'app.queue.reminder_confirmed',
            ['name' => $name ?: __('app.queue.a_patient')],
        );
        $this->failed = false;
    }

    private function run(callable $action, string $success): void
    {
        try {
            $action();
            $this->notice = $success;
            $this->failed = false;
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;
        }
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookings(): Collection
    {
        $bookings = $this->clinic()->bookings()
            ->with(['patient', 'visitType', 'reminders'])
            ->onDate($this->date)
            ->get();

        return app(QueueService::class)->sortBookings($bookings);
    }

    private function isToday(): bool
    {
        return $this->date === $this->clinic()->currentDay()->toDateString();
    }

    /**
     * The term once it is long enough to search on, under the same rule as
     * the API's; null before then.
     */
    private function searchTerm(): ?string
    {
        $term = mb_substr(trim($this->search), 0, 100);

        return mb_strlen($term) >= config('clinic.queue_search.min_chars') ? $term : null;
    }

    /**
     * Today's queue only, through QueueSearchService — the service behind
     * GET /api/v1/queue/search — so she reads out exactly what the mobile
     * app and the patient's tracking page show.
     *
     * @return Collection<int, array{booking: Booking, position: QueuePosition}>
     */
    private function matches(): Collection
    {
        $term = $this->searchTerm();

        if ($term === null || ! $this->isToday()) {
            return collect();
        }

        $this->requireAbility('queue.manage');

        return app(QueueSearchService::class)->search(
            $this->clinic(),
            $term,
            config('clinic.queue_search.limit'),
        )['matches'];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, int>
     */
    private function counts(Collection $bookings): array
    {
        return [
            'total' => $bookings->count(),
            'waiting' => $bookings->whereIn('status', [BookingStatus::BOOKED, BookingStatus::ARRIVED])->count(),
            'done' => $bookings->where('status', BookingStatus::DONE)->count(),
        ];
    }
}
