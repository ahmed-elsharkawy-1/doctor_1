<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Livewire\App\NewBooking;
use App\Livewire\App\Settings\Hours;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\VisitType;
use App\Services\V1\Messaging\TemplatePayloadResolver;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A clinic open 19:00 to 03:00. Everything after midnight belongs to the
 * evening it started on: booked under that day, listed on that day's queue,
 * and not closed out until the period has actually ended.
 */
class OvernightHoursTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private VisitType $checkup;

    protected function setUp(): void
    {
        parent::setUp();

        // 10 August 2026 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $this->checkup = $this->clinic->visitTypes()->first();
        $this->checkup->update(['duration_minutes' => 30]);

        Sanctum::actingAs($this->owner);

        $this->saveDay(DayOfWeek::MONDAY, [['19:00', '03:00']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $periods
     */
    private function saveDay(DayOfWeek $day, array $periods)
    {
        return $this->putJson(route('api.v1.schedule.update', $day->value), [
            'is_open' => true,
            'periods' => array_map(fn (array $period) => ['start_time' => $period[0], 'end_time' => $period[1]], $periods),
        ]);
    }

    /**
     * @return list<string>
     */
    private function starts(string $date, bool $availableOnly = false): array
    {
        $slots = $this->getJson(route('api.v1.slots', [
            'date' => $date,
            'visit_type_id' => $this->checkup->id,
        ]))->assertOk()->json('data.slots');

        if ($availableOnly) {
            $slots = array_filter($slots, fn (array $slot) => $slot['is_available']);
        }

        return array_values(array_column(array_column($slots, 'start_time'), 'value'));
    }

    private function book(string $startTime, string $phone = '01012225521')
    {
        return $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'سارة أحمد',
            'phone' => $phone,
            'visit_type_id' => $this->checkup->id,
            'date' => '2026-08-10',
            'start_time' => $startTime,
        ]);
    }

    public function test_a_period_may_run_past_midnight(): void
    {
        $this->assertSame(
            ['19:00', '03:00'],
            [
                $this->clinic->scheduleFor(DayOfWeek::MONDAY)->periods->first()->startTime(),
                $this->clinic->scheduleFor(DayOfWeek::MONDAY)->periods->first()->endTime(),
            ],
        );
    }

    /**
     * 09:00 to 05:00 is far more likely a typo than a twenty-hour day.
     */
    public function test_an_overnight_period_must_close_by_early_morning(): void
    {
        $this->saveDay(DayOfWeek::TUESDAY, [['19:00', '07:00']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_INVALID');
    }

    public function test_an_empty_period_is_still_rejected(): void
    {
        $this->saveDay(DayOfWeek::TUESDAY, [['19:00', '19:00']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_INVALID');
    }

    public function test_two_evenings_on_one_day_overlap_across_midnight(): void
    {
        $this->saveDay(DayOfWeek::TUESDAY, [['17:00', '01:00'], ['23:00', '02:00']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_OVERLAP');
    }

    /**
     * Monday's 01:00 already means Tuesday 01:00; a Monday-morning period at
     * that time would make it mean two things.
     */
    public function test_nothing_on_the_same_day_may_start_before_the_overnight_period_closes(): void
    {
        $this->saveDay(DayOfWeek::MONDAY, [['01:00', '02:00'], ['19:00', '03:00']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_OVERLAP');

        $this->saveDay(DayOfWeek::MONDAY, [['09:00', '14:00'], ['19:00', '03:00']])->assertOk();
    }

    public function test_the_next_day_cannot_open_before_last_night_closes(): void
    {
        $this->saveDay(DayOfWeek::TUESDAY, [['02:00', '05:00']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_OVERLAP');

        $this->saveDay(DayOfWeek::TUESDAY, [['03:00', '05:00']])->assertOk();

        // And the other way round: Sunday already opens at 02:00 on Monday.
        $this->saveDay(DayOfWeek::SUNDAY, [['20:00', '02:00']])->assertOk();
        $this->saveDay(DayOfWeek::MONDAY, [['01:00', '05:00']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SCHEDULE_PERIOD_OVERLAP');
    }

    /**
     * The hours screen the doctor actually uses: Monday runs to 03:00, so
     * neither Tuesday nor Monday itself may open anything before then — and
     * the doctor is told why, by name and time.
     *
     * @param  list<array{start_time: string, end_time: string}>  $periods
     */
    private function saveOnHoursScreen(DayOfWeek $day, array $periods)
    {
        return Livewire::actingAs($this->owner)->test(Hours::class)
            ->set("week.{$day->value}.is_open", true)
            ->set("week.{$day->value}.periods", $periods)
            ->call('saveDay', $day->value);
    }

    public function test_the_hours_screen_refuses_a_period_inside_last_nights_hours(): void
    {
        $clash = __('settings.schedule.overnight_clash', ['day' => DayOfWeek::MONDAY->label(), 'time' => '03:00']);

        // Tuesday 00:00–03:00 is still Monday night.
        $this->saveOnHoursScreen(DayOfWeek::TUESDAY, [['start_time' => '01:00', 'end_time' => '05:00']])
            ->assertSet('failed', true)
            ->assertSet('notice', $clash);

        // And so is Monday's own 01:00.
        $this->saveOnHoursScreen(DayOfWeek::MONDAY, [
            ['start_time' => '00:30', 'end_time' => '02:00'],
            ['start_time' => '19:00', 'end_time' => '03:00'],
        ])
            ->assertSet('failed', true)
            ->assertSet('notice', $clash);

        // A second evening on top of the first.
        $this->saveOnHoursScreen(DayOfWeek::MONDAY, [
            ['start_time' => '19:00', 'end_time' => '03:00'],
            ['start_time' => '22:00', 'end_time' => '23:00'],
        ])
            ->assertSet('failed', true)
            ->assertSet('notice', __('settings.schedule.period_overlap'));

        $this->assertSame(0, $this->clinic->scheduleFor(DayOfWeek::TUESDAY)->periods()->count());
        $this->assertSame(1, $this->clinic->scheduleFor(DayOfWeek::MONDAY)->periods()->count());

        // From 03:00 the time is free again.
        $this->saveOnHoursScreen(DayOfWeek::TUESDAY, [['start_time' => '03:00', 'end_time' => '05:00']])
            ->assertSet('failed', false);
    }

    public function test_slots_carry_on_past_midnight_in_order(): void
    {
        $starts = $this->starts('2026-08-10');

        $this->assertSame('19:00', $starts[0]);
        $this->assertSame(['23:30', '00:00', '00:30', '01:00', '01:30', '02:00', '02:30'], array_slice($starts, 9));
        $this->assertCount(16, $starts);
    }

    public function test_a_booking_after_midnight_is_saved_on_the_day_it_belongs_to(): void
    {
        $this->book('01:00')
            ->assertCreated()
            ->assertJsonPath('data.date.value', '2026-08-10')
            ->assertJsonPath('data.start_time.value', '01:00');

        $booking = Booking::sole();

        $this->assertSame('2026-08-10', $booking->visit_date->toDateString());
        $this->assertSame('2026-08-11 01:00:00', $booking->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-11 01:30:00', $booking->end_at->format('Y-m-d H:i:s'));
    }

    public function test_an_after_midnight_slot_is_taken_once_booked(): void
    {
        $this->book('01:00')->assertCreated();

        $this->book('01:00', '01098765432')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');

        $this->assertContains('00:30', $this->starts('2026-08-10', availableOnly: true));
        $this->assertNotContains('01:00', $this->starts('2026-08-10', availableOnly: true));
    }

    public function test_a_slot_hold_after_midnight_lands_on_the_right_night(): void
    {
        $this->postJson(route('api.v1.slot-holds.store'), [
            'visit_type_id' => $this->checkup->id,
            'date' => '2026-08-10',
            'start_time' => '02:30',
        ])->assertCreated();

        $this->assertDatabaseHas('slot_holds', [
            'visit_date' => '2026-08-10',
            'start_at' => '2026-08-11 02:30:00',
        ]);
        $this->assertNotContains('02:30', $this->starts('2026-08-10', availableOnly: true));
    }

    /**
     * At 01:10 on Tuesday the clinic is still working through Monday.
     */
    public function test_after_midnight_it_is_still_the_evening_that_began_yesterday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 01:10:00', 'Africa/Cairo'));

        $this->assertSame('2026-08-10', $this->clinic->currentDay()->toDateString());

        // Monday is still bookable, but only from now on.
        $this->assertSame(['01:30', '02:00', '02:30'], $this->starts('2026-08-10'));

        $this->book('02:00')->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-08-11 03:00:00', 'Africa/Cairo'));

        $this->assertSame('2026-08-11', $this->clinic->currentDay()->toDateString());
    }

    public function test_a_holiday_evening_does_not_hold_the_next_day_back(): void
    {
        $this->clinic->holidays()->create(['date' => '2026-08-10']);

        Carbon::setTestNow(Carbon::parse('2026-08-11 01:10:00', 'Africa/Cairo'));

        $this->assertSame('2026-08-11', $this->clinic->currentDay()->toDateString());
    }

    public function test_the_evening_is_not_closed_out_until_its_period_has_ended(): void
    {
        $booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-11 01:00', 'Africa/Cairo'))
            ->create(['visit_date' => '2026-08-10', 'status' => BookingStatus::BOOKED]);

        Carbon::setTestNow(Carbon::parse('2026-08-11 01:00:00', 'Africa/Cairo'));
        $this->artisan('clinic:close-day')->assertSuccessful();
        $this->assertSame(BookingStatus::BOOKED, $booking->refresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-08-11 04:00:00', 'Africa/Cairo'));
        $this->artisan('clinic:close-day')->assertSuccessful();
        $this->assertSame(BookingStatus::NO_SHOW, $booking->refresh()->status);
    }

    /**
     * The secretary's screen, opened at 01:10 on Tuesday: still on Monday,
     * and an after-midnight slot books under Monday.
     */
    public function test_the_booking_screen_works_the_night_through(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 01:10:00', 'Africa/Cairo'));

        Livewire::actingAs($this->owner)->test(NewBooking::class)
            ->assertSet('date', '2026-08-10')
            ->set('visitTypeId', $this->checkup->id)
            ->set('patientName', 'سلمى محمود')
            ->set('phone', '01001234501')
            ->call('selectSlot', '02:00')
            ->call('save')
            ->assertSet('failed', false);

        $booking = Booking::sole();

        $this->assertSame('2026-08-10', $booking->visit_date->toDateString());
        $this->assertSame('2026-08-11 02:00', $booking->start_at->format('Y-m-d H:i'));
    }

    /*
    |--------------------------------------------------------------------------
    | What the patient is told
    |--------------------------------------------------------------------------
    |
    | The patient sees Monday, like the clinic does. Under Monday's date the
    | time says "بعد منتصف الليل", so 01:00 is not read as Monday morning.
    */

    private function afterMidnight(): Booking
    {
        $this->book('01:00')->assertCreated();

        return Booking::sole();
    }

    public function test_only_a_visit_past_midnight_counts_as_after_midnight(): void
    {
        $this->assertTrue($this->afterMidnight()->startsAfterMidnight());

        $this->book('21:00', '01098765432')->assertCreated();

        $this->assertFalse(Booking::latest('id')->first()->startsAfterMidnight());
    }

    /**
     * @return list<string>
     */
    private function confirmation(Booking $booking): array
    {
        $template = new MessageTemplate(['key' => WhatsAppMessagingService::CONFIRMATION_KEY]);

        return app(TemplatePayloadResolver::class)->for($template, $booking, $this->clinic)->body;
    }

    public function test_the_whatsapp_confirmation_keeps_monday_and_says_after_midnight(): void
    {
        $body = $this->confirmation($this->afterMidnight());

        $this->assertSame(Carbon::parse('2026-08-10')->locale('ar')->isoFormat('D MMMM YYYY'), $body[1]);
        $this->assertSame('01:00 بعد منتصف الليل', $body[2]);
        $this->assertStringNotContainsString('فجر', implode(' ', $body));
    }

    public function test_an_evening_visit_keeps_its_plain_date_and_time(): void
    {
        $this->book('21:00')->assertCreated();

        $body = $this->confirmation(Booking::sole());

        $this->assertSame(Carbon::parse('2026-08-10')->locale('ar')->isoFormat('D MMMM YYYY'), $body[1]);
        $this->assertSame('09:00 مساءً', $body[2]);
    }

    public function test_the_tracking_page_shows_monday_and_after_midnight(): void
    {
        $booking = $this->afterMidnight();

        $this->get($booking->trackingUrl())
            ->assertOk()
            ->assertSee(Carbon::parse('2026-08-10')->translatedFormat('l، j/n/Y'))
            ->assertDontSee(Carbon::parse('2026-08-11')->translatedFormat('l، j/n/Y'))
            ->assertSee('بعد منتصف الليل');
    }

    public function test_the_patient_page_marks_slots_that_fall_after_midnight(): void
    {
        $this->clinic->update(['slug' => 'dr-sara', 'self_booking_enabled' => true]);
        $this->checkup->update(['is_self_bookable' => true]);

        config([
            'clinic.self_booking.require_otp' => false,
            // Every slot on screen; grouped, only the first stretch is open.
            'clinic.self_booking.slot_groups.min_to_group' => 100,
        ]);

        // Name and number first; only then does the page show times.
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', '01012225521')
            ->call('sendCode')
            ->set('date', '2026-08-10')
            ->assertViewHas('stage', 'appointment')
            ->assertSee('بعد منتصف الليل');
    }
}
