<?php

namespace App\Livewire\App;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Queue\BookingStatusService;
use App\Services\V1\Queue\QueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        ])->title(__('app.queue.title'));
    }

    public function goToDay(int $offset): void
    {
        $this->date = $this->safeDate($this->date)
            ->addDays($offset)
            ->toDateString();
    }

    public function today(): void
    {
        $this->date = Carbon::now($this->clinic()->timezone)->toDateString();
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
            ->with(['patient', 'visitType'])
            ->onDate($this->date)
            ->get();

        return app(QueueService::class)->sortBookings($bookings);
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
