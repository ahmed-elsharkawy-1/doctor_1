<?php

namespace App\Enums;

/**
 * Why a booking was cancelled — see SPEC §5.4.
 */
enum CancelReason: string
{
    /** The patient called to cancel. */
    case PATIENT_CANCELLED = 'patient_cancelled';

    /** Never arrived. Set by the secretary, or by the end-of-day job. */
    case NO_SHOW = 'no_show';

    /** Cancelled by a same-day postponement (SPEC §4.5). */
    case EMERGENCY = 'emergency';

    /** Left mid-visit at end of day — arrived or with_doctor, never completed. */
    case INCOMPLETE = 'incomplete';

    public function label(): string
    {
        return __('booking.cancel_reason.'.$this->value);
    }

    /**
     * Emergency cancellations are the only ones that put a patient on the
     * rebooking worklist.
     */
    public function requiresRebooking(): bool
    {
        return $this === self::EMERGENCY;
    }

    /**
     * Reasons the secretary can pick directly. `incomplete` is system-only.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::PATIENT_CANCELLED, self::NO_SHOW];
    }

    /**
     * @return array<string, string>
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
