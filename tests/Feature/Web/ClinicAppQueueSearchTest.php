<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Livewire\App\Queue;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The search box on the web queue: today's queue searched as she types, with
 * the same place and counts as GET /api/v1/queue/search — one service behind
 * both, so the web screen and the mobile app can never read out different
 * numbers.
 */
class ClinicAppQueueSearchTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $time, string $name, string $day = '2026-09-03'): Booking
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
        ]);

        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse($day.' '.$time, $this->clinic->timezone), 20)
            ->create(['patient_id' => $patient->id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function found(string $term): array
    {
        return Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', $term)
            ->viewData('matches')
            ->map(fn (array $match): array => [
                'id' => $match['booking']->id,
                'place' => $match['position']->place(),
                'ahead' => $match['position']->ahead,
                'ahead_emergency' => $match['position']->aheadEmergency,
                'behind' => $match['position']->behind(),
                'with_doctor' => $match['position']->withDoctor,
            ])
            ->all();
    }

    public function test_typing_finds_a_patient_with_their_place(): void
    {
        $this->booking('10:20', 'أحمد علي');
        $mine = $this->booking('11:00', 'سارة محمود');
        $this->booking('11:20', 'كريم حسن');

        $this->assertSame([[
            'id' => $mine->id,
            'place' => 2,
            'ahead' => 1,
            'ahead_emergency' => 0,
            'behind' => 1,
            'with_doctor' => 0,
        ]], $this->found('سارة'));
    }

    /**
     * The point of having one service: whatever the mobile app shows for a
     * patient, the web screen shows the same.
     */
    public function test_the_numbers_match_the_mobile_api(): void
    {
        $this->booking('09:40', 'أحمد علي')->update([
            'status' => BookingStatus::WITH_DOCTOR,
            'called_in_at' => now(),
        ]);
        $this->booking('10:20', 'منى سعيد');
        $this->booking('11:00', 'سارة محمود');
        $this->booking('13:00', 'سارة كمال')->update([
            'status' => BookingStatus::ARRIVED,
            'arrived_at' => now(),
            'queue_entered_at' => now(),
        ]);
        Booking::factory()->forClinic($this->clinic)->create([
            'booking_kind' => BookingKind::EMERGENCY,
            'start_at' => null,
            'end_at' => null,
            'status' => BookingStatus::ARRIVED,
            'arrived_at' => now(),
            'queue_entered_at' => now(),
        ]);

        Sanctum::actingAs($this->secretary);
        $api = collect($this->getJson(route('api.v1.queue.search', ['q' => 'سارة']))->assertOk()->json('data.items'))
            ->map(fn (array $item): array => [
                'id' => $item['booking']['id'],
                'place' => $item['place'],
                'ahead' => $item['ahead'],
                'ahead_emergency' => $item['ahead_emergency'],
                'behind' => $item['behind'],
                'with_doctor' => $item['with_doctor'],
            ])
            ->all();

        $this->assertCount(2, $api);
        $this->assertSame($api, $this->found('سارة'));
    }

    public function test_a_single_character_searches_nothing(): void
    {
        $this->booking('10:20', 'سارة محمود');

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', 'س')
            ->assertViewHas('matches', fn ($matches): bool => $matches->isEmpty())
            ->assertDontSee(__('app.queue.search.no_matches'));
    }

    public function test_the_minimum_length_is_the_apis(): void
    {
        config(['clinic.queue_search.min_chars' => 3]);
        $this->booking('10:20', 'سارة محمود');

        $this->assertSame([], $this->found('سا'));

        Sanctum::actingAs($this->secretary);
        $this->getJson(route('api.v1.queue.search', ['q' => 'سا']))->assertUnprocessable();
    }

    public function test_no_match_says_so(): void
    {
        $this->booking('10:20', 'سارة محمود');

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', 'كريم')
            ->assertSee(__('app.queue.search.no_matches'));
    }

    public function test_the_list_stops_at_the_limit(): void
    {
        config(['clinic.queue_search.limit' => 2]);
        $this->booking('10:20', 'سارة أ');
        $this->booking('10:40', 'سارة ب');
        $this->booking('11:00', 'سارة ج');

        $this->assertCount(2, $this->found('سارة'));
    }

    public function test_only_todays_queue_is_searched(): void
    {
        $this->booking('10:20', 'سارة محمود', day: '2026-09-04');

        $this->assertSame([], $this->found('سارة'));
    }

    /**
     * The search is today's queue, so it is offered only on today's list —
     * on another day it would answer about a day she is not looking at.
     */
    public function test_the_box_is_only_on_todays_list(): void
    {
        $this->booking('10:20', 'سارة محمود');

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->assertSee(__('app.queue.search.placeholder'))
            ->call('goToDay', 1)
            ->assertDontSee(__('app.queue.search.placeholder'))
            ->set('search', 'سارة')
            ->assertViewHas('matches', fn ($matches): bool => $matches->isEmpty());
    }

    public function test_clearing_empties_the_results(): void
    {
        $this->booking('10:20', 'سارة محمود');

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', 'سارة')
            ->assertViewHas('matches', fn ($matches): bool => $matches->count() === 1)
            ->call('clearSearch')
            ->assertSet('search', '')
            ->assertViewHas('matches', fn ($matches): bool => $matches->isEmpty());
    }

    public function test_a_patient_whose_turn_it_is_is_marked(): void
    {
        $this->booking('09:40', 'سارة محمود')->update([
            'status' => BookingStatus::ARRIVED,
            'arrived_at' => now(),
            'queue_entered_at' => now(),
        ]);

        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', 'سارة')
            ->assertSee(__('app.queue.search.is_next'));
    }

    /**
     * She reads out what the patient's page says: no estimate there, none
     * here either.
     */
    public function test_the_expected_time_follows_the_tracking_page(): void
    {
        $this->booking('10:20', 'أحمد علي');
        $this->booking('11:00', 'سارة محمود');

        config(['clinic.tracking.show_estimate' => false]);
        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', 'سارة')
            ->assertDontSee(__('app.queue.search.expected_at'));

        config(['clinic.tracking.show_estimate' => true]);
        Livewire::actingAs($this->secretary)
            ->test(Queue::class)
            ->set('search', 'سارة')
            ->assertSee(__('app.queue.search.expected_at'));
    }
}
