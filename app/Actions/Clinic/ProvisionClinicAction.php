<?php

namespace App\Actions\Clinic;

use App\Enums\DayOfWeek;
use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Gives a freshly created clinic everything it needs to be usable:
 * its specialty's default visit types, and a full seven-day week.
 *
 * Idempotent — running it again on a provisioned clinic changes nothing.
 */
class ProvisionClinicAction
{
    public function execute(Clinic $clinic, ?string $ownerPassword = null): Clinic
    {
        return DB::transaction(function () use ($clinic, $ownerPassword) {
            $this->seedVisitTypes($clinic);
            $this->seedWeek($clinic);
            $this->ensureSharedOwner($clinic, $ownerPassword);

            return $clinic->refresh();
        });
    }

    /**
     * Copies the specialty's default visit types. Prices start at zero — the
     * owner sets them before booking, since price is snapshotted per booking.
     */
    private function seedVisitTypes(Clinic $clinic): void
    {
        if ($clinic->visitTypes()->exists()) {
            return;
        }

        $defaults = $clinic->specialty?->defaultVisitTypes ?? collect();

        foreach ($defaults as $index => $default) {
            $clinic->visitTypes()->create([
                'name' => $default->name_ar,
                'duration_minutes' => $default->duration_minutes,
                'price' => 0,
                'is_active' => true,
                // Every specialty lists its new-concern visit type first; the
                // owner can move the flag afterwards.
                'is_new_patient_type' => $index === 0,
                'sort_order' => $default->sort_order,
            ]);
        }
    }

    /**
     * Creates all seven days, closed. The owner opens the ones they work and
     * adds periods — there is no implicit "open by default" day.
     */
    private function seedWeek(Clinic $clinic): void
    {
        $existing = $clinic->schedules()->pluck('day_of_week')->all();

        foreach (DayOfWeek::week() as $day) {
            if (in_array($day, $existing, true) || in_array($day->value, $existing, true)) {
                continue;
            }

            $clinic->schedules()->create([
                'day_of_week' => $day,
                'is_open' => false,
            ]);
        }
    }

    /**
     * V1 uses one shared clinic login. It is still stored as a User so
     * Sanctum tokens, abilities and audit columns keep using the normal path.
     */
    private function ensureSharedOwner(Clinic $clinic, ?string $password): void
    {
        $phone = $clinic->phone === null
            ? null
            : PhoneNumber::tryParse($clinic->phone, $clinic->country_code)?->e164;

        if ($phone === null) {
            return;
        }

        /** @var User|null $owner */
        $owner = $clinic->staff()->role(UserRole::CLINIC)->first();

        if ($owner === null && blank($password)) {
            return;
        }

        $attributes = [
            'name' => $clinic->name,
            'email' => 'clinic-'.$clinic->id.'@doctor1.local',
            'role' => UserRole::CLINIC,
            'phone' => $phone,
            'locale' => config('clinic.api.default_locale'),
            'is_active' => $clinic->is_active,
        ];

        if (filled($password)) {
            $attributes['password'] = $password;
        }

        $owner ??= new User;
        $owner->fill($attributes);
        $owner->email_verified_at ??= now();
        $owner->save();

        $owner->clinics()->syncWithoutDetaching([$clinic->id]);
    }
}
