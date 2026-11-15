<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\ClinicVisitLengthDay;
use App\Models\VisitType;
use App\Services\Messaging\OtpSender;
use App\Services\V1\Booking\SlotAvailabilityService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * A clinic can give one date its own visit length — every visit type, that
 * day only. The slot grid, holds and the bookings themselves all use it;
 * every other date keeps each visit type's own length, and nothing has to be
 * switched back afterwards.
 */
class VisitLengthDayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    /** A Saturday, with the next Saturday a normal day. */
    private const SPECIAL = '2026-08-08';

    private const NORMAL = '2026-08-15';

    private VisitType $checkup;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-07 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->delete();
        $schedule->periods()->create(['start_time' => '17:00', 'end_time' => '18:00']);

        $this->clinic->update([
            'booking_window_days' => 14,
            'patient_booking_window_days' => 14,
        ]);

        $this->checkup = $this->clinic->visitTypes()->first();
        $this->checkup->update(['duration_minutes' => 10]);

        ClinicVisitLengthDay::create([
            'clinic_id' => $this->clinic->id,
            'date' => self::SPECIAL,
            'minutes' => 20,
        ]);

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<string> */
    private function grid(string $date, ?VisitType $visitType = null): array
    {
        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic->fresh(),
            Carbon::parse($date, $this->clinic->timezone),
            $visitType ?? $this->checkup,
        );

        return array_map(
            fn ($slot) => $slot->startAt->format('H:i').'-'.$slot->endAt->format('H:i'),
            $availability->slots,
        );
    }

    private function book(string $date, string $time, string $phone = '01012225521'): TestResponse
    {
        return $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'سارة أحمد',
            'phone' => $phone,
            'visit_type_id' => $this->checkup->id,
            'date' => $date,
            'start_time' => $time,
        ]);
    }

    public function test_the_special_day_is_offered_on_its_own_length(): void
    {
        $this->assertSame(['17:00-17:20', '17:20-17:40', '17:40-18:00'], $this->grid(self::SPECIAL));
    }

    /** The app reads the day's length and its grid from the same answer. */
    public function test_the_slots_endpoint_reports_the_days_length(): void
    {
        $slots = fn (string $date) => $this->getJson(route('api.v1.slots', [
            'date' => $date,
            'visit_type_id' => $this->checkup->id,
        ]))->assertOk();

        $slots(self::SPECIAL)
            ->assertJsonPath('data.visit_type.duration_minutes', 20)
            ->assertJsonPath('data.slots.0.end_time.value', '17:20')
            ->assertJsonCount(3, 'data.slots');

        $slots(self::NORMAL)
            ->assertJsonPath('data.visit_type.duration_minutes', 10)
            ->assertJsonCount(6, 'data.slots');
    }

    public function test_every_other_day_keeps_the_visit_types_length(): void
    {
        $this->assertCount(6, $this->grid(self::NORMAL));
        $this->assertSame('17:00-17:10', $this->grid(self::NORMAL)[0]);
    }

    /** Every visit type that day, whatever its usual length. */
    public function test_it_applies_to_every_visit_type_that_day(): void
    {
        $followUp = VisitType::create([
            'clinic_id' => $this->clinic->id,
            'name' => 'إعادة',
            'duration_minutes' => 15,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->assertSame(['17:00-17:20', '17:20-17:40', '17:40-18:00'], $this->grid(self::SPECIAL, $followUp));
        $this->assertSame('17:00-17:15', $this->grid(self::NORMAL, $followUp)[0]);
    }

    public function test_a_booking_that_day_is_saved_with_the_days_length(): void
    {
        $this->book(self::SPECIAL, '17:20')
            ->assertCreated()
            ->assertJsonPath('data.end_time.value', '17:40');

        $booking = Booking::sole();
        $this->assertSame(20, $booking->duration_minutes);
    }

    public function test_a_booking_on_a_normal_day_is_unchanged(): void
    {
        $this->book(self::NORMAL, '17:10')
            ->assertCreated()
            ->assertJsonPath('data.end_time.value', '17:20');

        $this->assertSame(10, Booking::sole()->duration_minutes);
    }

    /** The old ten-minute grid is gone that day: 17:10 is not a start time. */
    public function test_a_time_off_the_days_grid_is_refused(): void
    {
        $this->book(self::SPECIAL, '17:10')->assertStatus(409);

        $this->assertSame(0, Booking::count());
    }

    /** Twenty minutes means twenty: the next patient cannot start inside it. */
    public function test_a_booked_slot_holds_the_whole_twenty_minutes(): void
    {
        $this->book(self::SPECIAL, '17:00')->assertCreated();

        $this->assertNotContains('17:00-17:20', $this->availableGrid(self::SPECIAL));
        $this->assertContains('17:20-17:40', $this->availableGrid(self::SPECIAL));
    }

    /** @return list<string> */
    private function availableGrid(string $date): array
    {
        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic->fresh(),
            Carbon::parse($date, $this->clinic->timezone),
            $this->checkup,
        );

        return array_values(array_map(
            fn ($slot) => $slot->startAt->format('H:i').'-'.$slot->endAt->format('H:i'),
            array_filter($availability->slots, fn ($slot) => $slot->isAvailable),
        ));
    }

    public function test_a_hold_that_day_covers_the_days_length(): void
    {
        $this->postJson(route('api.v1.slot-holds.store'), [
            'visit_type_id' => $this->checkup->id,
            'date' => self::SPECIAL,
            'start_time' => '17:20',
        ])->assertCreated();

        $this->assertDatabaseHas('slot_holds', [
            'start_at' => self::SPECIAL.' 17:20:00',
            'end_at' => self::SPECIAL.' 17:40:00',
        ]);
    }

    public function test_moving_a_booking_onto_the_day_takes_its_length_and_off_it_gives_it_back(): void
    {
        $this->book(self::NORMAL, '17:00')->assertCreated();
        $booking = Booking::sole();

        $move = fn (string $date, string $time) => $this->putJson(route('api.v1.bookings.update', $booking), [
            'patient_name' => 'سارة أحمد',
            'phone' => '01012225521',
            'visit_type_id' => $this->checkup->id,
            'date' => $date,
            'start_time' => $time,
        ])->assertOk();

        $move(self::SPECIAL, '17:40');
        $this->assertSame(20, $booking->fresh()->duration_minutes);

        $move(self::NORMAL, '17:30');
        $this->assertSame(10, $booking->fresh()->duration_minutes);
    }

    /** The client books through the same rule on the public page. */
    public function test_a_client_booking_online_gets_the_days_length(): void
    {
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['slug' => 'dr-sara', 'self_booking_enabled' => true]);
        $this->checkup->update(['is_self_bookable' => true]);

        $sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $sender);

        Livewire::test(BookVisit::class, ['slug' => 'dr-sara'])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', '01012225521')
            ->call('sendCode')
            ->set('code', $sender->lastCode())
            ->call('verifyCode')
            ->set('visitTypeId', $this->checkup->id)
            ->set('date', self::SPECIAL)
            ->call('selectSlot', '17:20')
            ->call('confirm')
            ->assertViewHas('stage', 'done');

        $booking = Booking::sole();
        $this->assertSame(20, $booking->duration_minutes);
        $this->assertSame(self::SPECIAL.' 17:40:00', $booking->end_at->format('Y-m-d H:i:s'));
    }

    public function test_another_clinics_day_changes_nothing_here(): void
    {
        ClinicVisitLengthDay::query()->delete();
        ClinicVisitLengthDay::create([
            'clinic_id' => $this->otherClinic()->id,
            'date' => self::SPECIAL,
            'minutes' => 20,
        ]);

        $this->assertSame('17:00-17:10', $this->grid(self::SPECIAL)[0]);
    }
}
