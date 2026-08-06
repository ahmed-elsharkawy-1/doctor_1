<?php

namespace Tests\Concerns;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\SpecialtySeeder;

trait InteractsWithClinic
{
    protected Clinic $clinic;

    protected User $owner;

    protected User $secretary;

    /**
     * A fully provisioned clinic (visit types seeded, all seven days created)
     * with an owner and a secretary attached.
     */
    protected function setUpClinic(string $specialtySlug = 'general'): void
    {
        $this->seed(SpecialtySeeder::class);

        $this->clinic = Clinic::factory()->create([
            'specialty_id' => Specialty::where('slug', $specialtySlug)->value('id'),
        ]);

        app(ProvisionClinicAction::class)->execute($this->clinic);

        $doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->owner = User::factory()->owner($doctor)->inClinic($this->clinic)->create();
        $this->secretary = User::factory()->secretary()->inClinic($this->clinic)->create();

        // The Flutter app always sends this. Without it, Symfony's test
        // request defaults to `en-us` and every `display` string comes back
        // in English.
        $this->withHeader('Accept-Language', 'ar');
    }

    /**
     * A second clinic, used to prove nothing leaks across the tenant boundary.
     */
    protected function otherClinic(): Clinic
    {
        $clinic = Clinic::factory()->create([
            'specialty_id' => Specialty::where('slug', 'dentistry')->value('id'),
        ]);

        app(ProvisionClinicAction::class)->execute($clinic);

        return $clinic;
    }
}
