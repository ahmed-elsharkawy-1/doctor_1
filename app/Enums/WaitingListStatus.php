<?php

namespace App\Enums;

/**
 * Where a waiting-list entry stands. Only WAITING is on the list; every other
 * case is how it came off.
 */
enum WaitingListStatus: string
{
    /** On the list, hoping a slot opens. */
    case WAITING = 'waiting';

    /** The secretary booked them — from the list, or any booking for that day. */
    case BOOKED = 'booked';

    /** The patient took themselves off. */
    case LEFT = 'left';

    /** The secretary took them off. */
    case REMOVED = 'removed';

    /** The day ended with no slot for them. */
    case CLOSED = 'closed';

    public function label(): string
    {
        return __('waiting_list.status.'.$this->value);
    }
}
