<?php

namespace App\DTOs\V1\Settings;

use App\Enums\DayOfWeek;

/**
 * A full replacement for one day of the weekly schedule.
 *
 * Replace-the-day rather than patch-a-period: the mockup edits a day as a
 * unit, and it removes any question of how to reconcile partial period edits.
 */
final class ScheduleDayData
{
    /**
     * @param  list<SchedulePeriodData>  $periods
     */
    public function __construct(
        public readonly DayOfWeek $day,
        public readonly bool $isOpen,
        public readonly array $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(DayOfWeek $day, array $validated): self
    {
        $isOpen = (bool) $validated['is_open'];

        return new self(
            day: $day,
            isOpen: $isOpen,
            // A closed day never keeps periods, whatever was submitted.
            periods: $isOpen
                ? array_map(
                    static fn (array $period) => SchedulePeriodData::fromArray($period),
                    array_values($validated['periods'] ?? []),
                )
                : [],
        );
    }
}
