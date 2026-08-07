<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Enums\CancelReason;
use App\Models\Booking;
use App\Models\Patient;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class PatientHistoryTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $this->patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'سارة أحمد',
            'code' => 'SAAH5521',
            'phone' => '+201012225521',
        ]);

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function visit(string $date, ?VisitType $type = null): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
            ->done()
            ->create([
                'patient_id' => $this->patient->id,
                'visit_type_id' => $type?->id ?? $this->clinic->visitTypes()->first()->id,
            ]);
    }

    private function file(): array
    {
        return $this->getJson(route('api.v1.patients.show', $this->patient))->assertOk()->json('data');
    }

    public function test_it_returns_the_patient_with_her_full_history(): void
    {
        $this->visit('2026-05-10');
        $this->visit('2026-07-02');

        $data = $this->file();

        $this->assertSame('SAAH5521', $data['patient']['code']);
        $this->assertSame('سارة أحمد', $data['patient']['name']);
        $this->assertCount(2, $data['visits']);
    }

    public function test_visits_are_newest_first(): void
    {
        $this->visit('2026-05-10');
        $this->visit('2026-07-02');
        $this->visit('2026-06-01');

        $dates = array_column(array_column($this->file()['visits'], 'date'), 'value');

        $this->assertSame(['2026-07-02', '2026-06-01', '2026-05-10'], $dates);
    }

    /**
     * This page has a call action, so the number is shown in full — unlike
     * search results (SPEC §4.4).
     */
    public function test_the_phone_is_unmasked_on_the_patients_own_page(): void
    {
        $data = $this->file();

        $this->assertSame('01012225521', $data['patient']['phone']['display']);
        $this->assertStringNotContainsString('*', $data['patient']['phone']['display']);
    }

    public function test_the_summary_counts_visits_no_shows_and_cancellations(): void
    {
        $this->visit('2026-05-10');
        $this->visit('2026-07-02');

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-06-01 09:00', 'Africa/Cairo'))
            ->cancelled(CancelReason::NO_SHOW)
            ->create(['patient_id' => $this->patient->id]);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-06-15 09:00', 'Africa/Cairo'))
            ->cancelled(CancelReason::PATIENT_CANCELLED)
            ->create(['patient_id' => $this->patient->id]);

        $summary = $this->file()['summary'];

        $this->assertSame(2, $summary['visits_count']);
        $this->assertSame(1, $summary['no_show_count']);
        $this->assertSame(2, $summary['cancelled_count']);
        $this->assertSame('2026-05-10', $summary['first_visit']['value']);
        $this->assertSame('2026-07-02', $summary['last_visit']['value']);
    }

    /**
     * A pattern of no-shows is exactly what the secretary wants to see.
     */
    public function test_cancellations_appear_in_the_history_with_their_reason(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-06-01 09:00', 'Africa/Cairo'))
            ->cancelled(CancelReason::NO_SHOW)
            ->create(['patient_id' => $this->patient->id]);

        $visit = $this->file()['visits'][0];

        $this->assertSame('cancelled', $visit['status']['value']);
        $this->assertSame('no_show', $visit['cancel_reason']['value']);
        $this->assertSame('لم تحضر', $visit['cancel_reason']['display']);
    }

    public function test_a_patient_with_no_visits_yet(): void
    {
        $data = $this->file();

        $this->assertSame([], $data['visits']);
        $this->assertSame(0, $data['summary']['visits_count']);
        $this->assertNull($data['summary']['first_visit']);
    }

    /**
     * An old visit must read as it was booked, not as the visit type reads now.
     */
    public function test_a_visit_keeps_its_snapshotted_duration(): void
    {
        $type = $this->clinic->visitTypes()->first();
        $type->update(['duration_minutes' => 20]);

        $this->visit('2026-05-10', $type);

        $type->update(['duration_minutes' => 45]);

        $this->assertSame(20, $this->file()['visits'][0]['visit_type']['duration_minutes']);
    }

    public function test_a_secretary_sees_no_prices_in_the_history(): void
    {
        $this->visit('2026-05-10');

        foreach ($this->file()['visits'] as $visit) {
            $this->assertArrayNotHasKey('price', $visit);
        }
    }

    public function test_the_owner_sees_prices_in_the_history(): void
    {
        $this->visit('2026-05-10');

        Sanctum::actingAs($this->owner);

        $this->assertArrayHasKey('price', $this->file()['visits'][0]);
    }

    public function test_another_clinics_patient_is_not_reachable(): void
    {
        $foreign = Patient::factory()->create(['clinic_id' => $this->otherClinic()->id]);

        $this->getJson(route('api.v1.patients.show', $foreign))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PATIENT_NOT_FOUND');
    }

    public function test_an_unknown_patient_returns_a_clear_error(): void
    {
        $this->getJson(route('api.v1.patients.show', 999999))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PATIENT_NOT_FOUND');
    }
}
