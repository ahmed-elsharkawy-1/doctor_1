<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class PatientSearchTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patient(string $name, string $code, string $phone): Patient
    {
        return Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
            'code' => $code,
            'phone' => $phone,
        ]);
    }

    private function search(?string $term = null): array
    {
        return $this->getJson(route('api.v1.patients.index', array_filter(['q' => $term])))
            ->assertOk()
            ->json('data');
    }

    public function test_an_empty_search_lists_everyone(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');
        $this->patient('منى عبد الله', 'MOAB5678', '+201012345678');

        $this->assertCount(2, $this->search()['items']);
    }

    public function test_it_finds_a_patient_by_part_of_her_name(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');
        $this->patient('منى عبد الله', 'MOAB5678', '+201012345678');

        $items = $this->search('سارة')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('SAAH5521', $items[0]['code']);
    }

    public function test_it_finds_a_patient_by_id_code(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');
        $this->patient('منى عبد الله', 'MOAB5678', '+201012345678');

        $items = $this->search('MOAB')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('منى عبد الله', $items[0]['name']);
    }

    /**
     * Not in the PRD, but the secretary usually has the number in front of her.
     */
    public function test_it_finds_a_patient_by_the_tail_of_her_phone(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');
        $this->patient('منى عبد الله', 'MOAB5678', '+201012345678');

        $items = $this->search('5678')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('MOAB5678', $items[0]['code']);
    }

    public function test_an_unmatched_term_returns_an_empty_list(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');

        $this->assertSame([], $this->search('مفيش')['items']);
    }

    /**
     * There is no call action on a search result — SPEC §4.4.
     */
    public function test_phones_are_masked_in_search_results(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');

        $item = $this->search()['items'][0];

        $this->assertSame('0101***5521', $item['phone']['display']);
        $this->assertSame('+201012225521', $item['phone']['value']);
    }

    public function test_it_counts_real_visits_only(): void
    {
        $patient = $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');

        Booking::factory()->count(2)->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-01 09:00', 'Africa/Cairo'))
            ->done()->create(['patient_id' => $patient->id]);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-02 09:00', 'Africa/Cairo'))
            ->noShow()->create(['patient_id' => $patient->id]);

        $this->assertSame(2, $this->search()['items'][0]['visits_count']);
        $this->assertSame('2026-08-01', $this->search()['items'][0]['last_visit']['value']);
    }

    public function test_results_are_paginated(): void
    {
        Patient::factory()->count(20)->create(['clinic_id' => $this->clinic->id]);

        $data = $this->getJson(route('api.v1.patients.index', ['per_page' => 5]))
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]])
            ->json('data');

        $this->assertCount(5, $data['items']);
        $this->assertSame(20, $data['meta']['total']);
        $this->assertSame(4, $data['meta']['last_page']);
    }

    public function test_the_page_size_is_capped(): void
    {
        $this->getJson(route('api.v1.patients.index', ['per_page' => 9999]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_another_clinics_patients_are_never_returned(): void
    {
        $this->patient('سارة أحمد', 'SAAH5521', '+201012225521');

        Patient::factory()->create([
            'clinic_id' => $this->otherClinic()->id,
            'name' => 'مريضة تانية',
        ]);

        $items = $this->search()['items'];

        $this->assertCount(1, $items);
        $this->assertSame('سارة أحمد', $items[0]['name']);
    }
}
