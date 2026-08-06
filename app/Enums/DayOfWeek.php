<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Days of the business week, Saturday-first — see SPEC §3.2.
 *
 * Note this is NOT Carbon's numbering (which is Sunday-first). Use
 * fromDate()/toCarbonDayOfWeek() to cross the boundary; never cast raw.
 */
enum DayOfWeek: int
{
    case SATURDAY = 0;
    case SUNDAY = 1;
    case MONDAY = 2;
    case TUESDAY = 3;
    case WEDNESDAY = 4;
    case THURSDAY = 5;
    case FRIDAY = 6;

    public function label(): string
    {
        return __('schedule.day.'.strtolower($this->name));
    }

    public static function fromDate(CarbonInterface $date): self
    {
        return self::fromCarbonDayOfWeek($date->dayOfWeek);
    }

    /**
     * Carbon numbers Sunday as 0; we number Saturday as 0.
     */
    public static function fromCarbonDayOfWeek(int $carbonDayOfWeek): self
    {
        return self::from(($carbonDayOfWeek + 1) % 7);
    }

    public function toCarbonDayOfWeek(): int
    {
        return ($this->value + 6) % 7;
    }

    /**
     * All seven days in business-week order.
     *
     * @return list<self>
     */
    public static function week(): array
    {
        return self::cases();
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
