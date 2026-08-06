<?php

namespace App\Services\V1\Settings;

use App\DTOs\V1\Settings\GeneralSettingsData;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Results\V1\Settings\BootstrapResult;

class ClinicSettingsService
{
    public function __construct(
        private readonly VisitTypeService $visitTypeService,
        private readonly ScheduleService $scheduleService,
        private readonly HolidayService $holidayService,
    ) {}

    /**
     * Everything the app needs on launch, in one round trip.
     */
    public function bootstrap(Clinic $clinic, User $user): BootstrapResult
    {
        $clinic->loadMissing('specialty');

        return new BootstrapResult(
            clinic: $clinic,
            user: $user,
            visitTypes: $this->visitTypeService->list($clinic),
            week: $this->scheduleService->week($clinic),
            holidays: $this->holidayService->list($clinic),
        );
    }

    public function updateGeneral(Clinic $clinic, GeneralSettingsData $data): Clinic
    {
        $attributes = $data->toAttributes();

        if ($attributes !== []) {
            $clinic->update($attributes);
        }

        return $clinic->refresh();
    }
}
