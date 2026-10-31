<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Patient;
use App\Services\V1\Queue\QueuePositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class QueueSearchTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 3 September 2026, mid-morning in the clinic's own zone.
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $time, string $name = 'مريض', ?string $phone = null, string $day = '2026-09-03'): Booking
    {
        $patient = Patient::factory()->create(array_filter([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
            'phone' => $phone,
        ]));

        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse($day.' '.$time, $this->clinic->timezone), 20)
            ->create(['patient_id' => $patient->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function search(string $term, array $query = []): array
    {
        return $this->getJson(route('api.v1.queue.search', ['q' => $term] + $query))
            ->assertOk()
            ->json('data');
    }

    public function test_it_finds_a_patient_by_name_with_their_place_and_who_is_either_side(): void
    {
        $this->booking('10:20', 'أحمد علي');
        $this->booking('10:40', 'منى سعيد');
        $this->booking('11:00', 'سارة محمود');
        $this->booking('11:20', 'كريم حسن');

        $data = $this->search('سارة');

        $this->assertSame('2026-09-03', $data['date']);
        $this->assertSame(4, $data['total']);
        $this->assertCount(1, $data['items']);

        $item = $data['items'][0];
        $this->assertSame('سارة محمود', $item['booking']['patient']['name']);
        $this->assertSame(3, $item['place']);
        $this->assertSame(2, $item['ahead']);
        $this->assertSame(1, $item['behind']);
        $this->assertSame('11:00', $item['expected_at']['value']);
        $this->assertFalse($item['is_next']);
    }

    public function test_it_finds_a_patient_by_the_tail_of_their_phone(): void
    {
        $this->booking('10:20', 'أحمد علي', '+201011112222');
        $this->booking('10:40', 'منى سعيد', '+201033334444');

        $items = $this->search('4444')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('منى سعيد', $items[0]['booking']['patient']['name']);
        $this->assertSame(2, $items[0]['place']);
    }

    public function test_it_finds_a_patient_by_code(): void
    {
        $booking = $this->booking('10:20', 'أحمد علي');

        $items = $this->search($booking->patient->code)['items'];

        $this->assertSame($booking->id, $items[0]['booking']['id']);
    }

    /**
     * The secretary and the patient's own page must read out the same
     * number — an evening patient who turned up early is not ahead.
     */
    public function test_the_counts_match_the_tracking_page(): void
    {
        $mine = $this->booking('11:00', 'سارة محمود');
        $this->booking('10:20', 'أحمد علي');
        $this->booking('13:00', 'كريم حسن')->update([
            'status' => BookingStatus::ARRIVED,
            'arrived_at' => now(),
            'queue_entered_at' => now(),
        ]);

        $item = $this->search('سارة')['items'][0];

        $this->assertSame(1, $item['ahead']);
        $this->assertSame(1, $item['behind']);
        $this->assertSame(
            $item['ahead'],
            app(QueuePositionService::class)->for($mine->fresh())->ahead,
        );
    }

    public function test_emergencies_ahead_are_split_out(): void
    {
        $this->booking('11:00', 'سارة محمود');
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 10:30', $this->clinic->timezone))
            ->create([
                'booking_kind' => BookingKind::EMERGENCY,
                'status' => BookingStatus::ARRIVED,
                'arrived_at' => now(),
                'queue_entered_at' => now(),
            ]);

        $item = $this->search('سارة')['items'][0];

        $this->assertSame(1, $item['ahead']);
        $this->assertSame(0, $item['ahead_normal']);
        $this->assertSame(1, $item['ahead_emergency']);
    }

    public function test_matches_come_first_in_line_first_and_stop_at_the_limit(): void
    {
        $this->booking('11:40', 'محمد ثالث');
        $this->booking('10:20', 'محمد أول');
        $this->booking('11:00', 'محمد ثاني');

        $items = $this->search('محمد', ['limit' => 2])['items'];

        $this->assertSame(['محمد أول', 'محمد ثاني'], array_map(
            fn (array $item): string => $item['booking']['patient']['name'],
            $items,
        ));
        $this->assertSame([1, 2], array_column($items, 'place'));
    }

    public function test_only_todays_queue_is_searched(): void
    {
        $this->booking('09:00', 'سارة خلصت')->update(['status' => BookingStatus::DONE]);
        $this->booking('09:20', 'سارة ألغت')->update(['status' => BookingStatus::CANCELLED]);
        $this->booking('09:40', 'سارة لم تحضر')->update(['status' => BookingStatus::NO_SHOW]);
        $this->booking('10:00', 'سارة بكرة', day: '2026-09-04');

        Booking::factory()->forClinic($other = $this->otherClinic())
            ->at(Carbon::parse('2026-09-03 10:20', $other->timezone))
            ->create(['patient_id' => Patient::factory()->create(['clinic_id' => $other->id, 'name' => 'سارة عيادة تانية'])->id]);

        $this->assertSame([], $this->search('سارة')['items']);
    }

    public function test_the_patient_with_the_doctor_is_left_out_of_the_count(): void
    {
        $this->booking('09:40', 'أحمد علي')->update([
            'status' => BookingStatus::WITH_DOCTOR,
            'called_in_at' => now(),
        ]);
        $this->booking('10:00', 'منى سعيد');
        $this->booking('10:20', 'سارة محمود');
        $this->booking('10:40', 'كريم حسن');

        $data = $this->search('سارة');
        $item = $data['items'][0];

        $this->assertSame(1, $item['ahead']);
        $this->assertSame(1, $item['with_doctor']);
        $this->assertSame(2, $item['place']);
        $this->assertSame(1, $item['behind']);
        $this->assertSame($item['total'], $item['with_doctor'] + $item['place'] + $item['behind']);
    }

    /**
     * An emergency the secretary added but who is not in the clinic yet is in
     * the day's queue, but nobody waits for them — so they are in nobody's
     * count, ahead or behind.
     */
    public function test_an_emergency_on_the_way_is_in_nobodys_count(): void
    {
        $this->booking('10:20', 'أحمد علي');
        $this->booking('11:00', 'سارة محمود');
        Booking::factory()->forClinic($this->clinic)->create([
            'booking_kind' => BookingKind::EMERGENCY,
            'start_at' => null,
            'end_at' => null,
            'status' => BookingStatus::BOOKED,
        ]);

        $data = $this->search('سارة');
        $item = $data['items'][0];

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $item['place']);
        $this->assertSame(0, $item['behind']);
        $this->assertSame(2, $item['total']);
        $this->assertSame($item['total'], $item['with_doctor'] + $item['place'] + $item['behind']);
    }

    /**
     * A visit nobody marked done while the next patient went in: both are in
     * the room, and neither is behind anyone.
     */
    public function test_two_patients_left_with_the_doctor_are_both_in_the_room(): void
    {
        $this->booking('09:20', 'أحمد علي')->update(['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);
        $this->booking('09:40', 'منى سعيد')->update(['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);
        $this->booking('11:00', 'سارة محمود');

        $item = $this->search('سارة')['items'][0];

        $this->assertSame(2, $item['with_doctor']);
        $this->assertSame(1, $item['place']);
        $this->assertSame(0, $item['behind']);
        $this->assertSame($item['total'], $item['with_doctor'] + $item['place'] + $item['behind']);
    }

    public function test_the_patient_with_the_doctor_is_found_and_is_their_turn(): void
    {
        $this->booking('09:40', 'سارة محمود')->update([
            'status' => BookingStatus::WITH_DOCTOR,
            'called_in_at' => now(),
        ]);

        $item = $this->search('سارة')['items'][0];

        $this->assertSame(1, $item['place']);
        $this->assertTrue($item['is_next']);
    }

    public function test_a_single_character_is_not_searched(): void
    {
        $this->getJson(route('api.v1.queue.search', ['q' => 'س']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_the_limit_is_capped(): void
    {
        $this->getJson(route('api.v1.queue.search', ['q' => 'سارة', 'limit' => 50]))
            ->assertUnprocessable();
    }
}
