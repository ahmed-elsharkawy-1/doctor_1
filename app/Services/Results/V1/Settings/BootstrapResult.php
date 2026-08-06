<?php

namespace App\Services\Results\V1\Settings;

use App\Models\Clinic;
use App\Models\ClinicHoliday;
use App\Models\ClinicSchedule;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Results\ServiceResult;
use Illuminate\Support\Collection;

/**
 * One launch call — clinic config, visit types, the week, holidays and what
 * this user is allowed to do (SPEC §6.6).
 */
final class BootstrapResult extends ServiceResult
{
    /**
     * @param  Collection<int, VisitType>  $visitTypes
     * @param  Collection<int, ClinicSchedule>  $week
     * @param  Collection<int, ClinicHoliday>  $holidays
     */
    public function __construct(
        private readonly Clinic $clinic,
        private readonly User $user,
        private readonly Collection $visitTypes,
        private readonly Collection $week,
        private readonly Collection $holidays,
    ) {}

    public function toArray(): array
    {
        $canSeePrices = $this->user->hasAbility('prices.view');

        return [
            'clinic' => (new ClinicSettingsResult($this->clinic))->toArray(),
            'visit_types' => VisitTypeResult::collection($this->visitTypes, $canSeePrices),
            'schedule' => ScheduleDayResult::collection($this->week),
            'holidays' => HolidayResult::collection($this->holidays),
            'abilities' => $this->user->role->abilities(),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role->value,
            ],
        ];
    }
}
