<?php

namespace Tests\Feature\SelfBooking;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Models\Clinic;
use App\Models\Specialty;
use App\Models\VisitType;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The switches that decide whether a clinic has a public booking page at all,
 * and what a patient may pick on it.
 *
 * The important one is the default: off, for clinics already running as much
 * as for new ones. Opening a doctor's day to a public form is a decision
 * somebody makes, never something a deploy does on their behalf.
 */
class SelfBookingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function clinic(array $attributes = []): Clinic
    {
        $this->seed(SpecialtySeeder::class);

        return Clinic::factory()->create($attributes + [
            'specialty_id' => Specialty::where('slug', 'general')->value('id'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The master switch
    |--------------------------------------------------------------------------
    */

    public function test_a_new_clinic_has_self_booking_switched_off(): void
    {
        $clinic = $this->clinic();

        $this->assertFalse($clinic->self_booking_enabled);
        $this->assertFalse($clinic->allowsSelfBooking());
    }

    /**
     * A deactivated clinic has no public presence at all — its landing page is
     * already a 404 — so it can never take a self-booking either, whatever the
     * flag says.
     */
    public function test_a_deactivated_clinic_never_allows_self_booking(): void
    {
        $clinic = $this->clinic(['self_booking_enabled' => true, 'is_active' => false]);

        $this->assertFalse($clinic->allowsSelfBooking());
    }

    public function test_an_active_clinic_with_the_switch_on_allows_self_booking(): void
    {
        $clinic = $this->clinic(['self_booking_enabled' => true]);

        $this->assertTrue($clinic->allowsSelfBooking());
    }

    /*
    |--------------------------------------------------------------------------
    | The patient window
    |--------------------------------------------------------------------------
    */

    public function test_a_clinic_that_set_no_patient_window_falls_back_to_the_platform_default(): void
    {
        config(['clinic.defaults.patient_booking_window_days' => 3]);

        $clinic = $this->clinic([
            'booking_window_days' => 7,
            'patient_booking_window_days' => null,
        ]);

        $this->assertSame(3, $clinic->patientBookingWindowDays());
    }

    public function test_a_clinics_own_patient_window_wins_over_the_default(): void
    {
        config(['clinic.defaults.patient_booking_window_days' => 3]);

        $clinic = $this->clinic([
            'booking_window_days' => 7,
            'patient_booking_window_days' => 5,
        ]);

        $this->assertSame(5, $clinic->patientBookingWindowDays());
    }

    /**
     * The form validates this too, but a seeder or a hand-written SQL fix does
     * not go through the form. Patients must never be able to book further
     * ahead than the clinic itself takes bookings.
     */
    public function test_the_patient_window_can_never_exceed_the_clinics_own(): void
    {
        $clinic = $this->clinic([
            'booking_window_days' => 7,
            'patient_booking_window_days' => 14,
        ]);

        $this->assertSame(7, $clinic->patientBookingWindowDays());
    }

    public function test_the_patient_window_is_never_zero(): void
    {
        config(['clinic.defaults.patient_booking_window_days' => 0]);

        $clinic = $this->clinic(['patient_booking_window_days' => null]);

        $this->assertSame(1, $clinic->patientBookingWindowDays());
    }

    /*
    |--------------------------------------------------------------------------
    | Which visit types a patient may pick
    |--------------------------------------------------------------------------
    */

    /**
     * True for every seeded type, not just the new-patient one: a returning
     * patient is usually booking a follow-up, so starting narrower would be
     * wrong for most of the people the page is for.
     */
    public function test_provisioning_makes_every_visit_type_self_bookable(): void
    {
        $clinic = $this->clinic();

        app(ProvisionClinicAction::class)->execute($clinic);

        $this->assertGreaterThan(0, $clinic->visitTypes()->count());
        $this->assertSame(0, $clinic->visitTypes()->where('is_self_bookable', false)->count());
    }

    public function test_the_self_bookable_scope_excludes_hidden_and_opted_out_types(): void
    {
        $clinic = $this->clinic();

        $offered = VisitType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'كشف']);
        VisitType::factory()->hidden()->create(['clinic_id' => $clinic->id, 'name' => 'نوع قديم']);
        VisitType::factory()->notSelfBookable()->create(['clinic_id' => $clinic->id, 'name' => 'عملية']);

        $this->assertSame(
            [$offered->id],
            $clinic->visitTypes()->selfBookable()->pluck('id')->all(),
        );
    }

    /**
     * Hiding a type takes it off the public page as well, without anyone
     * having to remember the second switch.
     */
    public function test_hiding_a_type_removes_it_from_the_public_page_too(): void
    {
        $clinic = $this->clinic();

        $visitType = VisitType::factory()->create(['clinic_id' => $clinic->id]);

        $this->assertTrue($visitType->is_self_bookable);

        $visitType->hide();

        $this->assertSame(0, $clinic->visitTypes()->selfBookable()->count());
    }
}
