<?php

namespace App\Livewire\App\Settings;

use App\Models\ClinicHoliday;
use App\Services\V1\Settings\HolidayService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Days the clinic is closed on top of its usual week.
 *
 * Closing a day that already has patients booked is almost always a mistake,
 * so the service refuses it and reports how many. The screen shows that count
 * and asks again rather than pushing past it quietly.
 */
class Holidays extends SettingsComponent
{
    public string $date = '';

    public string $note = '';

    public bool $showPast = false;

    /** Set when the service refused because the day already has bookings. */
    public ?int $bookingsOnDate = null;

    public function render(): View
    {
        return view('livewire.app.settings.holidays', [
            'holidays' => $this->list(),
        ])->title(__('app.settings.holidays'));
    }

    public function add(bool $force = false): void
    {
        $this->validate();

        $details = $this->run(
            fn () => app(HolidayService::class)->create(
                $this->clinic(),
                $this->date,
                $this->note === '' ? null : $this->note,
                $force,
            ),
            __('app.settings.holiday_added'),
        );

        // The service hands back how many patients are booked that day; the
        // screen shows the number before offering to close it anyway.
        $this->bookingsOnDate = $details['bookings_count'] ?? null;

        if (! $this->failed) {
            $this->reset(['date', 'note', 'bookingsOnDate']);
        }
    }

    public function forceAdd(): void
    {
        $this->add(force: true);
    }

    public function dismissForce(): void
    {
        $this->bookingsOnDate = null;
        $this->notice = null;
        $this->failed = false;
    }

    public function delete(int $holidayId): void
    {
        $this->run(
            fn () => app(HolidayService::class)->delete($this->clinic(), $holidayId),
            __('app.settings.holiday_deleted'),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return Collection<int, ClinicHoliday>
     */
    private function list(): Collection
    {
        return app(HolidayService::class)->list($this->clinic(), $this->showPast);
    }
}
