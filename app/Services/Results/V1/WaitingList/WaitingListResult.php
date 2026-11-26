<?php

namespace App\Services\Results\V1\WaitingList;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\WaitingListEntry;
use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Collection;

/**
 * A day's waiting list, first to join first, with the room left on it.
 */
final class WaitingListResult extends ServiceResult
{
    /**
     * @param  Collection<int, WaitingListEntry>  $entries
     */
    public function __construct(
        private readonly Clinic $clinic,
        private readonly string $date,
        private readonly Collection $entries,
        private readonly bool $clinicCanAdd,
        private readonly ?Branch $branch = null,
    ) {}

    public function toArray(): array
    {
        return [
            // The branch's own switch, under the clinic's (ELA-21).
            'enabled' => $this->offers(),
            'date' => Wire::date($this->date),
            'max_per_day' => $this->clinic->waitingListMaxPerDay(),
            'count' => $this->entries->count(),
            // Whether the secretary can still add to this day: open, not over,
            // today or within the week, and not at the cap.
            'can_add' => $this->offers()
                && $this->clinicCanAdd
                && $this->entries->count() < $this->clinic->waitingListMaxPerDay(),
            'items' => $this->entries
                ->values()
                ->map(fn (WaitingListEntry $entry, int $index): array => (new WaitingListEntryResult($entry, $index + 1))->toArray())
                ->all(),
        ];
    }

    private function offers(): bool
    {
        return $this->branch?->offersWaitingList() ?? $this->clinic->offersWaitingList();
    }
}
