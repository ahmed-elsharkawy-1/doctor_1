<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\Results\V1\Queue\DelayResult;
use App\Services\V1\Queue\DelayService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * "تأخير طارئ" — the doctor is held up, so the rest of today moves later.
 *
 * The secretary picks how long and from which appointment; that one and
 * everyone after it move, and a free gap absorbs part of the delay. The
 * screen asks once before doing it, because the service applies it at once.
 *
 * It decides nothing: who can be moved, how far each one goes and who gets a
 * message are DelayService's answers — the same service POST /emergency calls.
 */
class Delay extends ClinicComponent
{
    /** One of the offered delays, in minutes. */
    public int $minutes = 0;

    /** The booking the delay starts from. */
    public ?int $from = null;

    public bool $confirming = false;

    public ?string $notice = null;

    public bool $failed = false;

    /**
     * What the delay did, as the API returns it — shown in place of the form.
     *
     * @var array<string, mixed>|null
     */
    public ?array $result = null;

    public function mount(): void
    {
        $this->requireAbility('queue.manage');

        $allowed = $this->service()->allowedMinutes();
        // The mobile app opens on half an hour too.
        $this->minutes = in_array(30, $allowed, true) ? 30 : ($allowed[0] ?? 0);
    }

    public function render(): View
    {
        $startingPoints = $this->result === null ? $this->startingPoints() : collect();

        // The picked booking can stop being movable while the screen is open
        // — she went in to the doctor — so the choice falls back to the first
        // patient still waiting.
        if ($startingPoints->isNotEmpty() && $startingPoints->firstWhere('id', $this->from) === null) {
            $this->from = $startingPoints->first()->id;
        }

        return view('livewire.app.delay', [
            'today' => $this->clinic()->currentDay(),
            'options' => $this->service()->options(),
            'startingPoints' => $startingPoints,
            'picked' => $startingPoints->firstWhere('id', $this->from),
        ])->title(__('app.delay.title'));
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing
    |--------------------------------------------------------------------------
    */

    public function pickMinutes(int $minutes): void
    {
        if (in_array($minutes, $this->service()->allowedMinutes(), true)) {
            $this->minutes = $minutes;
        }

        $this->confirming = false;
    }

    public function pickFrom(int $bookingId): void
    {
        $this->from = $bookingId;
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

    /** Back to the form, for a second delay later in the day. */
    public function again(): void
    {
        $this->result = null;
        $this->notice = null;
        $this->failed = false;
        $this->confirming = false;
        $this->from = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Delaying
    |--------------------------------------------------------------------------
    */

    public function delay(): void
    {
        $this->requireAbility('queue.manage');
        $this->confirming = false;

        if ($this->from === null) {
            return;
        }

        try {
            $outcome = $this->service()->delay($this->clinic(), $this->from, $this->minutes);
        } catch (ApiException $e) {
            // Past the end of the day, or the patient is no longer waiting.
            // ApiException renders as JSON, which would break the Livewire
            // response, so it is shown on the page instead.
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->result = (new DelayResult($outcome))->toArray();
        $this->notice = trans_choice('booking.delay.delayed', max(1, $outcome->bookings->count()));
        $this->failed = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, Booking>
     */
    private function startingPoints(): Collection
    {
        $this->requireAbility('queue.manage');

        return $this->service()->startingPoints($this->clinic());
    }

    private function service(): DelayService
    {
        return app(DelayService::class);
    }
}
