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
