<?php

namespace App\Livewire\App;

use App\Enums\BookingSource;
use App\Exceptions\ApiException;
use App\Models\WaitingListEntry;
use App\Services\V1\Booking\BookingDaysService;
use App\Services\V1\Patients\PatientService;
use App\Services\V1\WaitingList\WaitingListService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * A day's waiting list: who asked for a full day, in the order they asked.
 *
 * When a slot opens the secretary calls the first patient and books them
 * from here — the booking carries the entry, which is what takes them off the
 * list and sends "booked from the waiting list". Patients who phone in can be
 * added too. Every rule is WaitingListService's.
 */
class WaitingList extends ClinicComponent
{
    #[Url(except: '')]
    public string $date = '';

    public string $patientName = '';

    public string $phone = '';

    public ?int $visitTypeId = null;

    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        $this->requireAbility('bookings.manage');

        $this->date = $this->safeDate($this->date)->toDateString();
        $this->visitTypeId = $this->clinic()->visitTypes()->active()->value('id');
    }

    public function render(): View
    {
        $this->requireAbility('bookings.manage');

        $clinic = $this->clinic();

        return view('livewire.app.waiting-list', [
            'enabled' => $clinic->offersWaitingList(),
            'entries' => $this->entries(),
            'max' => $clinic->waitingListMaxPerDay(),
            'joinable' => app(WaitingListService::class)->isJoinable($clinic, $this->date),
            'days' => app(BookingDaysService::class)->window($clinic),
            'visitTypes' => $clinic->visitTypes()->active()->orderBy('name')->get(),
        ])->title(__('waiting_list.app.title'));
    }

    public function selectDay(string $date): void
    {
        $this->date = $this->safeDate($date)->toDateString();
        $this->clearNotice();
    }

    /** A patient who phoned in, added to the day on screen. */
    public function add(): void
    {
        $this->requireAbility('bookings.manage');

        $this->validate([
            'patientName' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'visitTypeId' => ['nullable', 'integer'],
        ], [], [
            'patientName' => __('waiting_list.app.patient_name'),
            'phone' => __('waiting_list.app.phone'),
        ]);

        $clinic = $this->clinic();

        try {
            $patients = app(PatientService::class);
            $patient = $patients->findOrCreate(
                $clinic,
                $this->patientName,
                $patients->parsePhone($clinic, $this->phone),
            );

            app(WaitingListService::class)->join(
                $clinic,
                $patient,
                $clinic->visitTypes()->active()->whereKey($this->visitTypeId)->first(),
                $this->date,
                BookingSource::CLINIC,
                auth()->user(),
            );
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->reset(['patientName', 'phone']);
        $this->notice = __('waiting_list.joined_ok');
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
