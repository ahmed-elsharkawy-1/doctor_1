<?php

namespace Tests\Feature\Patient;

use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_numeric_code_is_assigned_after_insert_from_the_row_id(): void
    {
        $patient = Patient::factory()->create([
            'clinic_id' => Clinic::factory()->create()->id,
            'code' => null,
        ]);

        $this->assertSame(Patient::codeForId($patient->id), $patient->refresh()->code);
    }

    public function test_codes_are_numeric_and_padded_to_the_configured_length(): void
    {
        config()->set('clinic.patient_code.start_at', 60000);
        config()->set('clinic.patient_code.step', 1);
        config()->set('clinic.patient_code.min_length', 7);

        $patient = Patient::factory()->create([
            'clinic_id' => Clinic::factory()->create()->id,
            'code' => null,
        ]);

        $this->assertSame(str_pad((string) (60000 + $patient->id), 7, '0', STR_PAD_LEFT), $patient->code);
    }

    public function test_the_code_is_immutable_after_assignment(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => Clinic::factory()->create()->id]);
        $original = $patient->code;

        $patient->update(['code' => '99999']);

        $this->assertSame($original, $patient->refresh()->code);
    }

    public function test_existing_manual_code_is_respected_on_create(): void
    {
        $patient = Patient::factory()->create([
            'clinic_id' => Clinic::factory()->create()->id,
            'code' => '70000',
        ]);

        $this->assertSame('70000', $patient->refresh()->code);
    }
}
