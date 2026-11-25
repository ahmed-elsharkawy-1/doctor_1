<?php

namespace App\Services\V1\Settings;

use App\Models\ClinicSchedule;

/**
 * What saving a day's hours did (ELA-20): the saved day, plus what the
 * screen should say about it without refusing it.
 */
final readonly class ScheduleDayUpdate
{
    /**
     * @param  list<array{branch_id: int, branch: string, from: string, to: string, minutes: int}>  $shortGaps  another branch the same day, closer than the minimum gap
     * @param  int  $bookingsOutsideHours  upcoming bookings this day whose time is no longer in the hours; nothing moved them
     */
    public function __construct(
        public ClinicSchedule $schedule,
        public array $shortGaps,
        public int $bookingsOutsideHours,
    ) {}
}
