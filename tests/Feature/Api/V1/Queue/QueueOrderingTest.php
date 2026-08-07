<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class QueueOrderingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->today = Carbon::parse('2026-08-08');

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $time, string $name): Booking
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
        ]);

        return Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->today->copy()->setTime(...array_map('intval', explode(':', $time))))
            ->create(['patient_id' => $patient->id]);
    }

    private function queue(): array
    {
        return $this->getJson(route('api.v1.queue'))->assertOk()->json('data');
    }

    private function names(array $data): array
    {
        return array_column(array_column($data['items'], 'patient'), 'name');
    }

    public function test_it_defaults_to_today(): void
    {
        $this->booking('09:00', 'سارة');

        $data = $this->queue();

        $this->assertSame('2026-08-08', $data['date']['value']);
        $this->assertTrue($data['is_open']);
        $this->assertCount(1, $data['items']);
    }

    /**
     * Before anyone arrives, the list is simply the day's appointments in time
     * order.
     */
    public function test_nobody_arrived_yet_sorts_by_appointment_time(): void
    {
        $this->booking('10:00', 'ب');
        $this->booking('09:00', 'أ');
        $this->booking('11:00', 'ج');

        $this->assertSame(['أ', 'ب', 'ج'], $this->names($this->queue()));
    }

    /**
     * The whole point of SPEC §4.2 — checking in jumps you ahead of people
     * booked earlier.
     */
    public function test_arriving_moves_a_patient_above_those_who_have_not(): void
    {
        $early = $this->booking('09:00', 'أ');
        $late = $this->booking('11:00', 'ج');

        $this->postJson(route('api.v1.bookings.arrive', $late))->assertOk();

        $this->assertSame(['ج', 'أ'], $this->names($this->queue()));
        $this->assertSame($early->id, $early->id);
    }

    public function test_arrived_patients_sort_by_when_they_actually_arrived(): void
    {
        $first = $this->booking('11:00', 'ج');
        $second = $this->booking('09:00', 'أ');

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.arrive', $first))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:05:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.arrive', $second))->assertOk();

        $this->assertSame(['ج', 'أ'], $this->names($this->queue()));
    }

    /**
     * A patient booked at 09:00 who turns up at 10:30 goes behind everyone
     * already waiting.
     */
    public function test_a_late_patient_goes_behind_those_already_waiting(): void
    {
        $late = $this->booking('09:00', 'المتأخرة');
        $onTime = $this->booking('10:00', 'الحاضرة');

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.arrive', $onTime))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:30:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.arrive', $late))->assertOk();

        $this->assertSame(['الحاضرة', 'المتأخرة'], $this->names($this->queue()));
    }

    public function test_the_patient_with_the_doctor_is_always_first(): void
    {
        $inRoom = $this->booking('11:00', 'جوه');
        $waiting = $this->booking('09:00', 'مستنية');

        $this->postJson(route('api.v1.bookings.arrive', $waiting))->assertOk();
        $this->postJson(route('api.v1.bookings.arrive', $inRoom))->assertOk();
        $this->postJson(route('api.v1.bookings.call-in', $inRoom))->assertOk();

        $this->assertSame(['جوه', 'مستنية'], $this->names($this->queue()));
    }

    public function test_finished_patients_drop_to_the_bottom(): void
    {
        $done = $this->booking('09:00', 'خلصت');
        $this->booking('10:00', 'لسه');

        $this->postJson(route('api.v1.bookings.arrive', $done))->assertOk();
        $this->postJson(route('api.v1.bookings.call-in', $done))->assertOk();
        $this->postJson(route('api.v1.bookings.complete', $done))->assertOk();

        $this->assertSame(['لسه', 'خلصت'], $this->names($this->queue()));
    }

    /**
     * Only patients physically in the clinic hold a number.
     */
    public function test_positions_are_numbered_among_those_present_only(): void
    {
        $inRoom = $this->booking('09:00', 'جوه');
        $waiting = $this->booking('10:00', 'مستنية');
        $this->booking('11:00', 'لسه ماجاتش');

        $this->postJson(route('api.v1.bookings.arrive', $inRoom))->assertOk();
        $this->postJson(route('api.v1.bookings.call-in', $inRoom))->assertOk();
        $this->postJson(route('api.v1.bookings.arrive', $waiting))->assertOk();

        $items = collect($this->queue()['items'])->keyBy(fn ($item) => $item['patient']['name']);

        $this->assertSame(1, $items['جوه']['queue_position']);
        $this->assertSame(2, $items['مستنية']['queue_position']);
        $this->assertNull($items['لسه ماجاتش']['queue_position']);
    }

    public function test_positions_renumber_as_the_day_moves(): void
    {
        $first = $this->booking('09:00', 'أ');
        $second = $this->booking('10:00', 'ب');

        $this->postJson(route('api.v1.bookings.arrive', $first))->assertOk();
        $this->postJson(route('api.v1.bookings.arrive', $second))->assertOk();

        $before = collect($this->queue()['items'])->keyBy(fn ($i) => $i['patient']['name']);
        $this->assertSame(2, $before['ب']['queue_position']);

        $this->postJson(route('api.v1.bookings.call-in', $first))->assertOk();
        $this->postJson(route('api.v1.bookings.complete', $first))->assertOk();

        $after = collect($this->queue()['items'])->keyBy(fn ($i) => $i['patient']['name']);
        $this->assertSame(1, $after['ب']['queue_position']);
        $this->assertNull($after['أ']['queue_position']);
    }

    public function test_it_counts_who_is_left(): void
    {
        $done = $this->booking('09:00', 'خلصت');
        $this->booking('10:00', 'لسه');
        $this->booking('11:00', 'كمان واحدة');

        $this->postJson(route('api.v1.bookings.arrive', $done))->assertOk();
        $this->postJson(route('api.v1.bookings.call-in', $done))->assertOk();
        $this->postJson(route('api.v1.bookings.complete', $done))->assertOk();

        $counts = $this->queue()['counts'];

        $this->assertSame(2, $counts['pending']);
        $this->assertSame(1, $counts['done']);
        $this->assertSame(3, $counts['total']);
    }

    public function test_cancelled_bookings_are_hidden_unless_asked_for(): void
    {
        $cancelled = $this->booking('09:00', 'ملغية');
        $this->booking('10:00', 'شغالة');

        $this->postJson(route('api.v1.bookings.cancel', $cancelled), ['reason' => 'no_show'])->assertOk();

        $this->assertSame(['شغالة'], $this->names($this->queue()));

        $withCancelled = $this->getJson(route('api.v1.queue', ['include_cancelled' => 1]))->json('data');

        $this->assertCount(2, $withCancelled['items']);
        $this->assertSame('ملغية', $this->names($withCancelled)[1]);
    }

    public function test_each_card_carries_the_actions_the_app_may_offer(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        $items = collect($this->queue()['items'])->keyBy('id');
        $this->assertSame(['arrive', 'call', 'edit', 'no_show', 'cancel'], $items[$booking->id]['available_actions']);

        $this->postJson(route('api.v1.bookings.arrive', $booking))->assertOk();
        $items = collect($this->queue()['items'])->keyBy('id');
        $this->assertSame(['call_in', 'edit', 'no_show', 'cancel'], $items[$booking->id]['available_actions']);

        $this->postJson(route('api.v1.bookings.call-in', $booking))->assertOk();
        $items = collect($this->queue()['items'])->keyBy('id');
        $this->assertSame(['complete'], $items[$booking->id]['available_actions']);

        $this->postJson(route('api.v1.bookings.complete', $booking))->assertOk();
        $items = collect($this->queue()['items'])->keyBy('id');
        $this->assertSame([], $items[$booking->id]['available_actions']);
    }

    public function test_a_closed_day_is_reported_as_such(): void
    {
        $sunday = $this->today->copy()->addDay()->toDateString();

        $data = $this->getJson(route('api.v1.queue', ['date' => $sunday]))->assertOk()->json('data');

        $this->assertFalse($data['is_open']);
        $this->assertSame([], $data['items']);
    }

    public function test_another_clinics_queue_is_never_visible(): void
    {
        Booking::factory()->forClinic($this->otherClinic())
            ->at($this->today->copy()->setTime(9, 0))->create();

        $this->assertCount(0, $this->queue()['items']);
    }
}
