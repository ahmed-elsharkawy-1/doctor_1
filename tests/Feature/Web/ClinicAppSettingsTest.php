<?php

namespace Tests\Feature\Web;

use App\Enums\DayOfWeek;
use App\Livewire\App\Settings\General;
use App\Livewire\App\Settings\Holidays;
use App\Livewire\App\Settings\Hours;
use App\Livewire\App\Settings\VisitTypes;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppSettingsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | General
    |--------------------------------------------------------------------------
    */

    public function test_general_settings_save_through_the_service(): void
    {
        Livewire::actingAs($this->owner)
            ->test(General::class)
            ->set('bookingWindowDays', 14)
            ->set('firstVisitOnlyDays', 90)
            ->set('patientArrivalLeadMinutes', 45)
            ->call('save')
            ->assertSet('failed', false);

        $clinic = $this->clinic->fresh();

        $this->assertSame(14, $clinic->booking_window_days);
        $this->assertSame(90, $clinic->first_visit_only_days);
        $this->assertSame(45, $clinic->patient_arrival_lead_minutes);
    }

    public function test_the_booking_window_keeps_the_same_bounds_as_the_api(): void
    {
        Livewire::actingAs($this->owner)
            ->test(General::class)
            ->set('bookingWindowDays', 200)
            ->call('save')
            ->assertHasErrors('bookingWindowDays');

        $this->assertNotSame(200, $this->clinic->fresh()->booking_window_days);
    }

    public function test_an_arrival_lead_outside_the_offered_options_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(General::class)
            ->set('patientArrivalLeadMinutes', 7)
            ->call('save')
            ->assertHasErrors('patientArrivalLeadMinutes');
    }

    /*
    |--------------------------------------------------------------------------
    | Working hours
    |--------------------------------------------------------------------------
    */

    public function test_a_day_can_be_opened_with_periods(): void
    {
        $day = DayOfWeek::SUNDAY->value;

        Livewire::actingAs($this->owner)
            ->test(Hours::class)
            ->call('toggleDay', $day)
            ->set("week.{$day}.periods.0.start_time", '10:00')
            ->set("week.{$day}.periods.0.end_time", '15:00')
            ->call('saveDay', $day)
            ->assertSet('failed', false);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SUNDAY)->fresh();

        $this->assertTrue((bool) $schedule->is_open);
        $this->assertSame('10:00', $schedule->periods->first()->startTime());
        $this->assertSame('15:00', $schedule->periods->first()->endTime());
    }

    public function test_a_split_day_keeps_both_periods(): void
    {
        $day = DayOfWeek::SATURDAY->value;

        Livewire::actingAs($this->owner)
            ->test(Hours::class)
            ->call('toggleDay', $day)
            ->call('addPeriod', $day)
            ->call('saveDay', $day)
            ->assertSet('failed', false);

        $this->assertCount(2, $this->clinic->scheduleFor(DayOfWeek::SATURDAY)->fresh()->periods);
    }

    public function test_closing_a_day_drops_its_periods(): void
    {
        $day = DayOfWeek::MONDAY->value;
        $schedule = $this->clinic->scheduleFor(DayOfWeek::MONDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        Livewire::actingAs($this->owner)
            ->test(Hours::class)
            ->call('toggleDay', $day)
            ->call('saveDay', $day);

        $schedule = $schedule->fresh();

        // A closed day never keeps periods, whatever was on screen.
        $this->assertFalse((bool) $schedule->is_open);
        $this->assertCount(0, $schedule->periods);
    }

    /*
    |--------------------------------------------------------------------------
    | Visit types
    |--------------------------------------------------------------------------
    */

    public function test_a_visit_type_can_be_created(): void
    {
        Livewire::actingAs($this->owner)
            ->test(VisitTypes::class)
            ->call('startCreating')
            ->set('name', 'استشارة')
            ->set('durationMinutes', '25')
            ->set('price', '350')
            ->call('save')
            ->assertSet('failed', false);

        $created = $this->clinic->visitTypes()->where('name', 'استشارة')->firstOrFail();

        $this->assertSame(25, $created->duration_minutes);
        $this->assertSame('350.00', $created->price);
    }

    public function test_hiding_never_deletes_and_leaves_past_bookings_intact(): void
    {
        $visitType = $this->clinic->visitTypes()->active()->first();

        $booking = Booking::factory()->forClinic($this->clinic)->done()
            ->create(['visit_type_id' => $visitType->id]);

        Livewire::actingAs($this->owner)
            ->test(VisitTypes::class)
            ->call('hide', $visitType->id)
            ->assertSet('failed', false);

        // The row survives, and the booking still points at it.
        $this->assertFalse((bool) $visitType->fresh()->is_active);
        $this->assertSame($visitType->id, $booking->fresh()->visit_type_id);
    }

    public function test_the_last_active_visit_type_cannot_be_hidden(): void
    {
        $types = $this->clinic->visitTypes()->active()->get();
        $last = $types->pop();

        foreach ($types as $type) {
            $type->hide();
        }

        Livewire::actingAs($this->owner)
            ->test(VisitTypes::class)
            ->call('hide', $last->id)
            // The service refuses; the screen reports rather than deciding.
            ->assertSet('failed', true);

        $this->assertTrue((bool) $last->fresh()->is_active);
    }

    public function test_repricing_a_visit_type_never_rewrites_a_past_booking(): void
    {
        $visitType = $this->clinic->visitTypes()->active()->first();

        $booking = Booking::factory()->forClinic($this->clinic)->done()
            ->create(['visit_type_id' => $visitType->id, 'price' => 250]);

        Livewire::actingAs($this->owner)
            ->test(VisitTypes::class)
            ->call('startEditing', $visitType->id)
            ->set('price', '999')
            ->call('save')
            ->assertSet('failed', false);

        $this->assertSame('999.00', $visitType->fresh()->price);
        $this->assertSame('250.00', $booking->fresh()->price);
    }

    /*
    |--------------------------------------------------------------------------
    | Holidays
    |--------------------------------------------------------------------------
    */

    public function test_a_holiday_can_be_added(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Holidays::class)
            ->set('date', '2026-10-06')
            ->set('note', 'إجازة رسمية')
            ->call('add')
            ->assertSet('failed', false);

        $this->assertSame(1, $this->clinic->holidays()->whereDate('date', '2026-10-06')->count());
    }

    public function test_closing_a_day_with_bookings_asks_before_doing_it(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-10-07 09:00', $this->clinic->timezone))
            ->create();

        $page = Livewire::actingAs($this->owner)
            ->test(Holidays::class)
            ->set('date', '2026-10-07')
            ->call('add')
            ->assertSet('failed', true)
            // The count comes back from the service so the screen can say it.
            ->assertSet('bookingsOnDate', 1);

        $this->assertSame(0, $this->clinic->holidays()->whereDate('date', '2026-10-07')->count());

        $page->call('forceAdd')->assertSet('failed', false);

        $this->assertSame(1, $this->clinic->holidays()->whereDate('date', '2026-10-07')->count());
    }

    public function test_the_same_day_cannot_be_closed_twice(): void
    {
        $this->clinic->holidays()->create(['date' => '2026-10-08']);

        Livewire::actingAs($this->owner)
            ->test(Holidays::class)
            ->set('date', '2026-10-08')
            ->call('add')
            ->assertSet('failed', true);

        $this->assertSame(1, $this->clinic->holidays()->whereDate('date', '2026-10-08')->count());
    }

    public function test_a_holiday_can_be_removed(): void
    {
        $holiday = $this->clinic->holidays()->create(['date' => '2026-10-09']);

        Livewire::actingAs($this->owner)
            ->test(Holidays::class)
            ->call('delete', $holiday->id)
            ->assertSet('failed', false);

        $this->assertSame(0, $this->clinic->holidays()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function test_settings_need_the_ability(): void
    {
        $this->get(route('app.settings'))->assertRedirect(route('app.login'));
    }
}
