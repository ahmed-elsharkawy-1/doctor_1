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
    protected string $ability = 'schedule.manage';

    public string $date = '';

    public string $note = '';

    /** The last day off of a range; empty for one day (ELA-20). */
    public string $endDate = '';

    /** 'all' for every branch (the default), 'some' for $branchIds. */
    public string $scope = 'all';

    /** @var list<string> */
    public array $branchIds = [];

    public bool $showPast = false;

    /** Set when the service refused because the day already has bookings. */
    public ?int $bookingsOnDate = null;

    public function render(): View
    {
        return view('livewire.app.settings.holidays', [
            // One entry per range or set of branches, not per row.
            'holidays' => $this->list()->groupBy(fn (ClinicHoliday $h) => $h->group_id ?? 'h'.$h->id),
            'branches' => auth()->user()->accessibleBranches($this->clinic()),
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
                endDate: $this->endDate === '' ? null : $this->endDate,
                branchIds: $this->scope === 'some' ? array_map('intval', $this->branchIds) : null,
                actor: auth()->user(),
            ),
            __('app.settings.holiday_added'),
        );

        // The service hands back how many patients are booked that day; the
        // screen shows the number before offering to close it anyway.
        $this->bookingsOnDate = $details['bookings_count'] ?? null;

        if (! $this->failed) {
            $this->reset(['date', 'endDate', 'note', 'scope', 'branchIds', 'bookingsOnDate']);
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
            fn () => app(HolidayService::class)->delete($this->clinic(), $holidayId, auth()->user()),
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
            'endDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'scope' => ['required', 'in:all,some'],
            'branchIds' => ['required_if:scope,some', 'array'],
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
