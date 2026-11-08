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
 * Patients join it themselves, on the public booking page, or the secretary
 * adds one who phoned in — here, or from the mobile app (POST /waiting-list),
 * through the same WaitingListService::addByClinic(). When a slot opens the
 * secretary calls the first patient and books them from here: the booking carries the entry, which is what takes
 * them off the list and sends "booked from the waiting list". Every rule is
 * WaitingListService's.
 */
class WaitingList extends ClinicComponent
{
    #[Url(except: '')]
    public string $date = '';

    public ?string $notice = null;

    public bool $failed = false;

    /** The "add a patient who phoned in" form, for the selected day. */
    public bool $adding = false;

    public string $addName = '';

    public string $addPhone = '';

    public ?int $addVisitTypeId = null;

    public function mount(): void
    {
        $this->requireAbility('bookings.manage');

        $this->date = $this->safeDate($this->date)->toDateString();
    }

    public function render(): View
    {
        $this->requireAbility('bookings.manage');

        $clinic = $this->clinic();

        $entries = $this->entries();
        $service = app(WaitingListService::class);

        return view('livewire.app.waiting-list', [
            'enabled' => $clinic->offersWaitingList(),
            'entries' => $entries,
            // As the API's `can_add`: switched on, the day still open, within
            // the week, and below the cap. Whether it is full is asked when
            // she submits.
            'canAdd' => $clinic->offersWaitingList()
                && $service->canClinicAddOn($clinic, $this->date)
                && $entries->count() < $clinic->waitingListMaxPerDay(),
            'visitTypes' => $clinic->visitTypes()->active()->orderBy('id')->get(),
            'max' => $clinic->waitingListMaxPerDay(),
            'lastAddDay' => $service->lastClinicAddDay($clinic),
            'days' => app(BookingDaysService::class)->window($clinic),
        ])->title(__('waiting_list.app.title'));
    }

    public function selectDay(string $date): void
    {
        $this->date = $this->safeDate($date)->toDateString();
        $this->adding = false;
        $this->clearNotice();
    }

    public function startAdding(): void
    {
        $this->adding = true;
        $this->clearNotice();
    }

    public function cancelAdding(): void
    {
        $this->reset('adding', 'addName', 'addPhone', 'addVisitTypeId');
        $this->resetValidation();
    }

    /**
     * A patient who phoned in for the selected day. Every rule — the day must
     * be full for that visit type, the cap, already booked — is the service's,
     * exactly as for the mobile app.
     */
    public function add(): void
    {
        $this->requireAbility('bookings.manage');

        $this->validate([
            'addName' => ['required', 'string', 'max:255'],
            'addPhone' => ['required', 'string', 'max:32'],
            'addVisitTypeId' => ['required', 'integer'],
        ], attributes: [
            'addName' => __('waiting_list.app.add_name'),
            'addPhone' => __('waiting_list.app.add_phone'),
            'addVisitTypeId' => __('waiting_list.app.add_visit_type'),
        ]);

        try {
            $entry = app(WaitingListService::class)->addByClinic(
                $this->clinic(),
                auth()->user(),
                $this->date,
                visitTypeId: (int) $this->addVisitTypeId,
                name: $this->addName,
                phone: $this->addPhone,
            );
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->cancelAdding();
        $this->notice = $entry->wasRecentlyCreated
            ? __('waiting_list.added_ok')
            : __('waiting_list.already_on');
        $this->failed = false;
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
