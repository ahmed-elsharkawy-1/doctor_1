<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Queue\PostponeService;
use App\Services\V1\Queue\QueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "تأجيل مواعيد اليوم" — the clinic's worst day.
 *
 * Postponing cancels the chosen bookings with reason `emergency`, which frees
 * their slots and puts every one of those patients on the rebooking worklist.
 * Because it is destructive and plural, the screen names who it is about to
 * affect and asks again before doing it.
 *
 * It decides nothing: who can still be postponed is QueueService's answer, and
 * the cancelling is PostponeService's — the same pair the mobile API calls.
 */
class Postpone extends ClinicComponent
{
    #[Url(as: 'date', except: '')]
    public string $date = '';

    /**
     * Booking ids the secretary picked. Empty means everyone on the day, which
     * is what the service understands `null` to mean.
     *
     * @var list<int>
     */
    public array $selected = [];

    public bool $confirming = false;

    public ?string $notice = null;

    public bool $failed = false;

    /** The call list, shown in place of the form once the day is postponed. */
    public bool $done = false;

    public function mount(): void
    {
        $this->requireAbility('queue.manage');

        if ($this->date === '') {
            $this->date = app(SlotAvailabilityService::class)
                ->today($this->clinic())
                ->toDateString();
        }
    }

    public function render(): View
    {
        $candidates = $this->done ? collect() : $this->candidates();

        return view('livewire.app.postpone', [
            'candidates' => $candidates,
            'affected' => $this->affected($candidates),
        ])->title(__('app.postpone.title'));
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing
    |--------------------------------------------------------------------------
    */

    public function goToDay(int $offset): void
    {
        $this->date = $this->safeDate($this->date)->addDays($offset)->toDateString();

        $this->resetChoice();
    }

    public function today(): void
    {
        $this->date = app(SlotAvailabilityService::class)
            ->today($this->clinic())
            ->toDateString();

        $this->resetChoice();
    }

    public function toggle(int $bookingId): void
    {
        $this->selected = in_array($bookingId, $this->selected, true)
            ? array_values(array_diff($this->selected, [$bookingId]))
            : [...$this->selected, $bookingId];

        $this->confirming = false;
    }

    /** Back to "everyone on the day". */
    public function selectAll(): void
    {
        $this->selected = [];
        $this->confirming = false;
    }

    public function confirm(): void
    {
        $this->confirming = true;
    }

    public function dismiss(): void
    {
        $this->confirming = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Postponing
    |--------------------------------------------------------------------------
    */

    public function postpone(): void
    {
        $this->requireAbility('queue.manage');

        try {
            $postponed = app(PostponeService::class)->postpone(
                $this->clinic(),
                $this->safeDate($this->date),
                $this->selected === [] ? null : $this->selected,
            );
        } catch (ApiException $e) {
            // Nothing left to postpone, most likely — the day emptied while
            // the screen was open. ApiException renders as JSON, which would
            // break the Livewire response.
            $this->notice = $e->getMessage();
            $this->failed = true;
            $this->confirming = false;

            return;
        }

        $this->notice = __('booking.postponed', ['count' => $postponed->count()]);
        $this->failed = false;
        $this->confirming = false;
        $this->done = true;
        $this->selected = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, Booking>
     */
    private function candidates(): Collection
    {
        $this->requireAbility('queue.manage');

        return app(QueueService::class)->postponeCandidates(
            $this->clinic(),
            $this->safeDate($this->date),
        );
    }

    /**
     * What the confirmation is about to cancel: the picked rows, or all of
     * them when nothing is picked.
     *
     * @param  Collection<int, Booking>  $candidates
     * @return Collection<int, Booking>
     */
    private function affected(Collection $candidates): Collection
    {
        if ($this->selected === []) {
            return $candidates;
        }

        return $candidates->whereIn('id', $this->selected)->values();
    }

    private function resetChoice(): void
    {
        $this->selected = [];
        $this->confirming = false;
        $this->done = false;
        $this->notice = null;
        $this->failed = false;
    }
}
