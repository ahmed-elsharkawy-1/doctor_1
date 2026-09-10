<?php

namespace App\Livewire\App\Settings;

use App\DTOs\V1\Settings\ScheduleDayData;
use App\Enums\DayOfWeek;
use App\Services\V1\Settings\ScheduleService;
use Illuminate\Contracts\View\View;

/**
 * The working week, edited a day at a time.
 *
 * A day is replaced whole rather than patched period by period — that is what
 * ScheduleDayData expects, and it removes any question of how to reconcile a
 * partial edit.
 */
class Hours extends SettingsComponent
{
    /** @var array<int, array{is_open: bool, periods: list<array{start_time: string, end_time: string}>}> */
    public array $week = [];

    public function mount(): void
    {
        parent::mount();

        $this->load();
    }

    public function render(): View
    {
        return view('livewire.app.settings.hours', [
            'days' => DayOfWeek::cases(),
            'maxPeriods' => config('clinic.schedule.max_periods_per_day'),
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

        $this->run(function () use ($dayOfWeek, $day): void {
            app(ScheduleService::class)->updateDay(
                $this->clinic(),
                ScheduleDayData::fromArray($dayOfWeek, [
                    'is_open' => $this->week[$day]['is_open'],
                    'periods' => $this->week[$day]['periods'],
                ]),
            );
        }, __('app.settings.saved'));

        $this->load();
    }

    private function load(): void
    {
        $this->week = [];

        foreach (app(ScheduleService::class)->week($this->clinic()) as $schedule) {
            $this->week[$schedule->day_of_week->value] = [
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
    }
}
