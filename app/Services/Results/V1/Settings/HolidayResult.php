<?php

namespace App\Services\Results\V1\Settings;

use App\Models\ClinicHoliday;
use App\Services\Results\ServiceResult;
use App\Support\Wire;
use Illuminate\Support\Collection;

final class HolidayResult extends ServiceResult
{
    public function __construct(private readonly ClinicHoliday $holiday) {}

    public function toArray(): array
    {
        return [
            'id' => $this->holiday->id,
            'date' => Wire::date($this->holiday->date),
            'note' => $this->holiday->note,
            // Null: every branch (ELA-20).
            'branch_id' => $this->holiday->branch_id,
            'branch' => $this->holiday->branch?->name,
            // Shared by the other days and branches entered with this one;
            // deleting any of them deletes them all.
            'group_id' => $this->holiday->group_id,
        ];
    }

    /**
     * @param  Collection<int, ClinicHoliday>  $holidays
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $holidays): array
    {
        return $holidays
            ->map(fn (ClinicHoliday $holiday) => (new self($holiday))->toArray())
            ->values()
            ->all();
    }
}
