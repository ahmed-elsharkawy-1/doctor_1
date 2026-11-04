<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\WaitingListEntry;
use App\Services\V1\Booking\BookingDaysService;
use App\Services\V1\WaitingList\WaitingListService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * A day's waiting list: who asked for a full day, in the order they asked.
 *
 * Patients join it themselves, on the public booking page — the clinic never
 * adds anyone. When a slot opens the secretary calls the first patient and
 * books them from here: the booking carries the entry, which is what takes
 * them off the list and sends "booked from the waiting list". Every rule is
 * WaitingListService's.
 */
class WaitingList extends ClinicComponent
{
    #[Url(except: '')]
    public string $date = '';

    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        $this->requireAbility('bookings.manage');

        $this->date = $this->safeDate($this->date)->toDateString();
    }

    public function render(): View
    {
        $this->requireAbility('bookings.manage');

        $clinic = $this->clinic();

        return view('livewire.app.waiting-list', [
            'enabled' => $clinic->offersWaitingList(),
            'entries' => $this->entries(),
            'max' => $clinic->waitingListMaxPerDay(),
            'days' => app(BookingDaysService::class)->window($clinic),
        ])->title(__('waiting_list.app.title'));
    }

    public function selectDay(string $date): void
    {
        $this->date = $this->safeDate($date)->toDateString();
        $this->clearNotice();
    }

    public function remove(int $entryId): void
    {
        $this->requireAbility('bookings.manage');

        try {
            $service = app(WaitingListService::class);
            $service->remove($service->find($this->clinic(), $entryId));
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->notice = __('waiting_list.removed_ok');
        $this->failed = false;
    }

    /**
     * @return Collection<int, WaitingListEntry>
     */
    private function entries(): Collection
    {
        return app(WaitingListService::class)->forDay($this->clinic(), $this->date);
    }

    private function clearNotice(): void
    {
        $this->notice = null;
        $this->failed = false;
    }
}
