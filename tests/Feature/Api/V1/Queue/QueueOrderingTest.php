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

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

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

    private function calendar(): array
    {
        return $this->getJson(route('api.v1.bookings.calendar', [
            'from' => '2026-08-08',
            'to' => '2026-08-08',
        ]))->assertOk()->json('data');
    }

    public function test_cards_sort_by_appointment_time_not_arrival_time(): void
    {
        $late = $this->booking('11:00', 'ج');
        $this->booking('09:00', 'أ');

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:05:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.status', $late), ['to' => 'arrived'])->assertOk();

        $names = array_column(array_column($this->calendar()['bookings']['2026-08-08'], 'patient'), 'name');

        $this->assertSame(['أ', 'ج'], $names);
    }

    public function test_cards_have_next_status_and_no_queue_position(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        $card = collect($this->calendar()['bookings']['2026-08-08'])->firstWhere('id', $booking->id);

        $this->assertSame('arrived', $card['next_status']['value']);
        $this->assertSame(['call', 'whatsapp', 'edit', 'no_show', 'cancel'], $card['available_actions']);
        $this->assertArrayNotHasKey('queue_position', $card);
    }

    public function test_with_doctor_cards_can_be_cancelled_but_not_marked_no_show(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        $this->postJson(route('api.v1.bookings.status', $booking), ['to' => 'arrived'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $booking), ['to' => 'with_doctor'])->assertOk();

        $card = collect($this->calendar()['bookings']['2026-08-08'])->firstWhere('id', $booking->id);

        $this->assertSame(['whatsapp', 'cancel'], $card['available_actions']);
    }

    public function test_counts_include_finished_cancelled_and_no_show_bookings(): void
    {
        $done = $this->booking('09:00', 'خلصت');
        $this->booking('10:00', 'لسه');
        $this->booking('11:00', 'ملغية')->update(['status' => 'cancelled']);
        $this->booking('12:00', 'لم يحضر')->update(['status' => 'no_show']);

        $this->postJson(route('api.v1.bookings.status', $done), ['to' => 'arrived'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $done), ['to' => 'with_doctor'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $done), ['to' => 'done'])->assertOk();

        $counts = $this->calendar()['days'][0]['counts'];

        $this->assertSame(4, $counts['total']);
        $this->assertSame(1, $counts['booked']);
        $this->assertSame(1, $counts['done']);
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(1, $counts['no_show']);
    }

    public function test_home_returns_today_counts_and_upcoming_cards(): void
    {
        $this->booking('09:00', 'أ');
        $this->booking('10:00', 'ب');

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:30:00', 'Africa/Cairo'));

        $data = $this->getJson(route('api.v1.home'))->assertOk()->json('data');

        $this->assertSame(2, $data['today']['counts']['total']);
        $this->assertSame(['ب'], array_column(array_column($data['upcoming'], 'patient'), 'name'));
    }

    public function test_another_clinics_bookings_are_never_visible(): void
    {
        Booking::factory()->forClinic($this->otherClinic())
            ->at($this->today->copy()->setTime(9, 0))->create();

        $this->assertSame([], $this->calendar()['bookings']);
    }
}
