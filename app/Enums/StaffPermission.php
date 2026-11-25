<?php

namespace App\Enums;

/**
 * The extra powers a doctor can hand to a team member (ELA-17).
 *
 * The doctor holds all of them. Anyone else holds only the ones granted, and
 * only within the branches they are assigned to. Without any, a team member
 * does the daily work: bookings, the queue, the waiting list.
 *
 * Each value is also the ability name it unlocks in UserRole, so routes and
 * screens gate on it the same way as on every other ability.
 */
enum StaffPermission: string
{
    case MANAGE_BRANCHES = 'branches.manage';

    case MANAGE_SCHEDULE = 'schedule.manage';

    public function label(): string
    {
        return __('user.permission.'.str_replace('.', '_', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
