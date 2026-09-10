<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\QueueService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Sending a WhatsApp template to a whole day, or to a few patients on it.
 *
 * The screen with the longest reach and the least undo, so it says what it is
 * about to do before it does it: how many will receive the message, how many
 * will be skipped for having no WhatsApp consent, and — for `day_cancelled` —
 * that sending it *cancels* every pending booking on the day.
 *
 * Only templates the service flags as broadcast are offered, so a booking
 * confirmation or a visit-completed review request can never be sent to a
 * roomful of people. That rule is the service's; this screen only shows what
 * it hands back.
 */
class Messages extends ClinicComponent
{
    /** Sending this template cancels the day it is sent to. */
    public const CANCELS_THE_DAY = 'day_cancelled';

    #[Url(as: 'date', except: '')]
    public string $date = '';

    public ?string $templateKey = null;

    /**
     * Chosen recipients. Empty means everyone pending on the day, which is
     * what the service understands an empty list to mean.
     *
     * @var list<int>
     */
    public array $selected = [];

    public bool $confirming = false;

    public ?string $notice = null;

    public bool $failed = false;

    /** @var array<string, mixed>|null the service's own report of what went out */
    public ?array $result = null;

    public function mount(): void
    {
        $this->requireAbility('bookings.manage');

        if ($this->date === '') {
            $this->date = app(SlotAvailabilityService::class)
                ->today($this->clinic())
                ->toDateString();
        }
    }

    public function render(): View
    {
        $recipients = $this->recipients();

        return view('livewire.app.messages', [
            'templates' => $this->templates(),
            'recipients' => $recipients,
            'willSend' => $recipients->filter(fn (Booking $b) => $b->patient?->whatsapp_opt_in_at !== null),
            'willSkip' => $recipients->filter(fn (Booking $b) => $b->patient?->whatsapp_opt_in_at === null),
        ])->title(__('app.messages.title'));
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

    public function selectTemplate(string $key): void
    {
        $this->templateKey = $key;
        $this->confirming = false;
        $this->result = null;
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
        $this->confirming = $this->templateKey !== null;
    }

    public function dismiss(): void
    {
        $this->confirming = false;
    }

    public function cancelsTheDay(): bool
    {
        return $this->templateKey === self::CANCELS_THE_DAY;
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function send(): void
    {
        $this->requireAbility('bookings.manage');

        if ($this->templateKey === null) {
            return;
        }

        try {
            $this->result = app(WhatsAppMessagingService::class)->broadcast(
                $this->clinic(),
                $this->templateKey,
                $this->safeDate($this->date),
                $this->selected === [] ? null : $this->selected,
            );
        } catch (ApiException $e) {
            // A per-booking template, or one that was deactivated while the
            // screen was open. ApiException renders as JSON, which would break
            // the Livewire response.
            $this->notice = $e->getMessage();
            $this->failed = true;
            $this->confirming = false;

            return;
        }

        $this->notice = __('app.messages.sent', ['count' => $this->result['sent_count']]);
        $this->failed = false;
        $this->confirming = false;
        $this->selected = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, MessageTemplate>
     */
    private function templates(): Collection
    {
        return app(WhatsAppMessagingService::class)->templates();
    }

    /**
     * Who the message would go to: everyone still pending on the day, or the
     * chosen few. Same selection the broadcast itself makes, asked of the same
     * service the postpone screen asks.
     *
     * @return Collection<int, Booking>
     */
    private function recipients(): Collection
    {
        $this->requireAbility('bookings.manage');

        $pending = app(QueueService::class)->postponeCandidates(
            $this->clinic(),
            $this->safeDate($this->date),
        );

        if ($this->selected === []) {
            return $pending;
        }

        return $pending->whereIn('id', $this->selected)->values();
    }

    private function resetChoice(): void
    {
        $this->selected = [];
        $this->confirming = false;
        $this->result = null;
        $this->notice = null;
        $this->failed = false;
    }
}
