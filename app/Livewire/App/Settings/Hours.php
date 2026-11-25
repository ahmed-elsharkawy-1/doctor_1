<?php

namespace App\Livewire\App\Settings;

use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\DayOfWeek;
use App\Models\Branch;
use App\Models\ClinicSchedule;
use App\Services\V1\Settings\ScheduleService;
use App\Support\Wire;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * The working week, edited a day at a time, for one branch at a time
 * (ELA-20). With two or more branches the screen opens on the branch picked
 * in the header, has its own picker, and shows the doctor's whole week
 * across branches above the editor.
 *
 * A day is replaced whole rather than patched period by period — that is what
 * ScheduleDayData expects, and it removes any question of how to reconcile a
 * partial edit.
 */
class Hours extends SettingsComponent
{
    protected string $ability = 'schedule.manage';

    /** @var array<int, array{is_open: bool, periods: list<array{start_time: string, end_time: string}>}> */
    public array $week = [];

    /** @var array<int, array{failed: bool, text: string, warnings: list<string>}> what saving each day said */
    public array $dayMessages = [];

    /** The branch being edited; always one this account works at. */
    public ?int $branchId = null;

    public function mount(): void
    {
        parent::mount();

        $this->branchId = $this->branch()->id;
        $this->load();
    }

    public function updatedBranchId(): void
    {
        $this->notice = null;
        $this->load();
    }

    public function render(): View
    {
        $branches = $this->branches();

        return view('livewire.app.settings.hours', [
            'days' => DayOfWeek::cases(),
            'maxPeriods' => config('clinic.schedule.max_periods_per_day'),
            'branches' => $branches,
            // The doctor's week across branches; only worth a table with two.
            'overview' => $branches->count() > 1 ? app(ScheduleService::class)->weeks($branches) : collect(),
        ])->title(__('app.settings.hours'));
    }

    public function toggleDay(int $day): void
    {
        $this->week[$day]['is_open'] = ! $this->week[$day]['is_open'];

        // A day that opens with nothing on it needs somewhere to start.
        if ($this->week[$day]['is_open'] && $this->week[$day]['periods'] === []) {
            $this->week[$day]['periods'][] = ['start_time' => '09:00', 'end_time' => '14:00'];
        }
    }

    public function addPeriod(int $day): void
    {
        if (count($this->week[$day]['periods']) >= config('clinic.schedule.max_periods_per_day')) {
            return;
        }

        $this->week[$day]['periods'][] = ['start_time' => '17:00', 'end_time' => '21:00'];
    }

    public function removePeriod(int $day, int $index): void
    {
        unset($this->week[$day]['periods'][$index]);
        $this->week[$day]['periods'] = array_values($this->week[$day]['periods']);
    }

    public function saveDay(int $day): void
    {
        $dayOfWeek = DayOfWeek::from($day);
        $branch = $this->branches()->firstWhere('id', $this->branchId);

        // Taken off this branch while the screen was open: the typed hours
        // were meant for it, so they go nowhere else.
        if ($branch === null) {
            $this->branchId = $this->branch()->id;
            $this->load();
            $this->dayMessages[$day] = ['failed' => true, 'text' => __('settings.branch.not_found'), 'warnings' => []];

            return;
        }

        $update = null;

        $this->run(function () use ($dayOfWeek, $day, $branch, &$update): void {
            $update = app(ScheduleService::class)->updateDay(
                $branch,
                ScheduleDayData::fromArray($dayOfWeek, [
                    'is_open' => $this->week[$day]['is_open'],
                    'periods' => $this->week[$day]['periods'],
                ]),
            );
        }, __('app.settings.saved'));

        // Said on the day's own card, where the Save button is: on a phone the
        // top of the page is a long way up.
        $this->dayMessages[$day] = ['failed' => $this->failed, 'text' => (string) $this->notice, 'warnings' => []];
        $this->notice = null;

        // Refused: the typed times stay, so they can be corrected.
        if ($update === null) {
            return;
        }

        // Saved; these only need saying (ELA-20).
        foreach ($update->shortGaps as $gap) {
            $this->dayMessages[$day]['warnings'][] = __('settings.schedule.short_gap', [
                'from' => Wire::time($gap['from'])['display'],
                'to' => Wire::time($gap['to'])['display'],
            ] + $gap);
        }

        if ($update->bookingsOutsideHours > 0) {
            $this->dayMessages[$day]['warnings'][] = trans_choice(
                'settings.schedule.bookings_outside',
                $update->bookingsOutsideHours,
                ['count' => $update->bookingsOutsideHours],
            );
        }

        // Only this day reloads: unsaved edits on the others are kept.
        $this->week[$day] = $this->dayState($update->schedule);
    }

    private function load(): void
    {
        $this->week = [];
        $this->dayMessages = [];

        foreach (app(ScheduleService::class)->week($this->editedBranch()) as $schedule) {
            $this->week[$schedule->day_of_week->value] = $this->dayState($schedule);
        }
    }

    /** @return array{is_open: bool, periods: list<array{start_time: string, end_time: string}>} */
    private function dayState(ClinicSchedule $schedule): array
    {
        return [
            'is_open' => (bool) $schedule->is_open,
            'periods' => $schedule->periods
                ->map(fn ($period) => [
                    'start_time' => $period->startTime(),
                    'end_time' => $period->endTime(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The branch picked on this screen, if this account still works there;
     * otherwise the one it is on.
     */
    private function editedBranch(): Branch
    {
        $branch = $this->branches()->firstWhere('id', $this->branchId) ?? $this->branch();
        $this->branchId = $branch->id;

        return $branch;
    }

    /** @return Collection<int, Branch> */
    private function branches(): Collection
    {
        return auth()->user()->accessibleBranches($this->clinic());
    }
}
