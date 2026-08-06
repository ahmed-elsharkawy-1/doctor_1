<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Models\Booking;
use App\Models\Patient;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class PatientLookupTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private VisitType $checkup;

    private VisitType $followUp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        // Provisioning flags the first seeded type as the "new concern" one.
        $this->checkup = $this->clinic->visitTypes()->where('is_new_patient_type', true)->firstOrFail();
        $this->followUp = $this->clinic->visitTypes()->where('is_new_patient_type', false)->firstOrFail();

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patientWithVisit(string $phone = '+201012225521'): Patient
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'سارة أحمد',
            'phone' => $phone,
            'code' => 'SAAH5521',
        ]);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-01 09:00', 'Africa/Cairo'))
            ->done()
            ->create([
                'patient_id' => $patient->id,
                'visit_type_id' => $this->followUp->id,
            ]);

        return $patient;
    }

    public function test_an_unknown_phone_returns_not_found(): void
    {
        $this->postJson(route('api.v1.patients.lookup'), ['phone' => '01099999999'])
            ->assertOk()
            ->assertJsonPath('data.found', false)
            ->assertJsonPath('data.patient', null)
            ->assertJsonPath('data.is_returning', false)
            ->assertJsonPath('data.visit_type_mismatch', false);
    }

    public function test_a_known_phone_returns_the_patient_and_her_visit_count(): void
    {
        $this->patientWithVisit();

        $this->postJson(route('api.v1.patients.lookup'), ['phone' => '01012225521'])
            ->assertOk()
            ->assertJsonPath('data.found', true)
            ->assertJsonPath('data.patient.code', 'SAAH5521')
            ->assertJsonPath('data.patient.name', 'سارة أحمد')
            ->assertJsonPath('data.patient.visits_count', 1)
            ->assertJsonPath('data.is_returning', true)
            ->assertJsonPath('data.last_visit.visit_type.id', $this->followUp->id);
    }

    public function test_the_phone_is_matched_however_it_is_typed(): void
    {
        $this->patientWithVisit();

        foreach (['01012225521', '+201012225521', '0101 222 5521', '00201012225521'] as $input) {
            $this->postJson(route('api.v1.patients.lookup'), ['phone' => $input])
                ->assertOk()
                ->assertJsonPath('data.found', true);
        }
    }

    /**
     * Matching is on phone alone — a different name means the secretary
     * mistyped, or a family member is booking on the same number.
     */
    public function test_a_different_typed_name_raises_a_conflict_flag(): void
    {
        $this->patientWithVisit();

        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01012225521',
            'name' => 'سارة أحمد محمد',
        ])
            ->assertOk()
            ->assertJsonPath('data.name_conflict', true);
    }

    public function test_the_same_name_raises_no_conflict(): void
    {
        $this->patientWithVisit();

        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01012225521',
            'name' => 'سارة أحمد',
        ])
            ->assertOk()
            ->assertJsonPath('data.name_conflict', false);
    }

    /**
     * The heart of the P0 mismatch warning: a returning patient booked under
     * the clinic's "new concern" visit type.
     */
    public function test_a_returning_patient_booked_as_a_new_concern_is_flagged(): void
    {
        $this->patientWithVisit();

        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01012225521',
            'visit_type_id' => $this->checkup->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.visit_type_mismatch', true);
    }

    public function test_a_returning_patient_booked_as_a_follow_up_is_not_flagged(): void
    {
        $this->patientWithVisit();

        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01012225521',
            'visit_type_id' => $this->followUp->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.visit_type_mismatch', false);
    }

    public function test_a_brand_new_patient_is_never_flagged(): void
    {
        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01099999999',
            'visit_type_id' => $this->checkup->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.visit_type_mismatch', false);
    }

    /**
     * The flag drives the warning, not a hardcoded visit-type name — moving it
     * moves the warning.
     */
    public function test_moving_the_flag_moves_the_warning(): void
    {
        $this->patientWithVisit();

        $this->checkup->update(['is_new_patient_type' => false]);
        $this->followUp->update(['is_new_patient_type' => true]);

        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01012225521',
            'visit_type_id' => $this->checkup->id,
        ])->assertJsonPath('data.visit_type_mismatch', false);

        $this->postJson(route('api.v1.patients.lookup'), [
            'phone' => '01012225521',
            'visit_type_id' => $this->followUp->id,
        ])->assertJsonPath('data.visit_type_mismatch', true);
    }

    public function test_a_patient_of_another_clinic_is_not_found(): void
    {
        $other = $this->otherClinic();

        Patient::factory()->create([
            'clinic_id' => $other->id,
            'phone' => '+201012225521',
        ]);

        $this->postJson(route('api.v1.patients.lookup'), ['phone' => '01012225521'])
            ->assertOk()
            ->assertJsonPath('data.found', false);
    }

    public function test_an_invalid_phone_is_rejected(): void
    {
        $this->postJson(route('api.v1.patients.lookup'), ['phone' => '123'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PHONE_NUMBER');
    }
}
