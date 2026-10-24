<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Livewire\App\Delay;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Support\DelayLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The web side of "تأخير طارئ". The rules themselves are DelayService's and
 * are proven in EmergencyDelayTest; this proves the screen drives them.
 */
class ClinicAppDelayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 20:30', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $at, BookingStatus $status = BookingStatus::BOOKED, string $date = '2026-08-08'): Booking
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse($at), 20)
            ->create(['status' => $status, 'visit_date' => $date, 'patient_id' => $patient->id]);
    }

    private function startOf(Booking $booking): string
    {
        return $booking->fresh()->start_at->format('Y-m-d H:i');
    }

    public function test_the_screen_is_in_the_navigation_and_opens(): void
    {
        $this->actingAs($this->owner)
            ->get(route('app.delay'))
            ->assertOk()
            ->assertSee(__('app.nav.delay'))
            ->assertSee(__('app.delay.title'))
            // Postponing a day is hidden from the menu for now, not removed.
            ->assertDontSee(__('app.nav.postpone'))
            ->assertDontSee(route('app.postpone'));

        $this->actingAs($this->owner)->get(route('app.postpone'))->assertOk();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('app.delay'))->assertRedirect(route('app.login'));
    }

    public function test_only_todays_waiting_patients_can_start_a_delay_and_the_first_is_picked(): void
    {
        $this->booking('2026-08-08 20:40', BookingStatus::WITH_DOCTOR);
        $first = $this->booking('2026-08-08 21:00', BookingStatus::ARRIVED);
        $next = $this->booking('2026-08-08 21:20');
        $this->booking('2026-08-08 21:40', BookingStatus::DONE);
        $this->booking('2026-08-09 21:00', date: '2026-08-09');
        $emergency = $this->booking('2026-08-08 22:00');
        $emergency->update(['booking_kind' => BookingKind::EMERGENCY]);

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->assertViewHas('startingPoints', fn ($points) => $points->pluck('id')->all() === [$first->id, $next->id])
            ->assertSet('from', $first->id)
            ->assertSet('minutes', 30);
    }

    public function test_the_offered_delays_come_worded_and_nothing_else_can_be_picked(): void
    {
        $this->booking('2026-08-08 21:00');

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->assertSee(DelayLabel::for(90))
            ->call('pickMinutes', 90)
            ->assertSet('minutes', 90)
            ->call('pickMinutes', 45)
            ->assertSet('minutes', 90);
    }

    public function test_delaying_asks_first_then_moves_everyone_and_shows_the_new_times(): void
    {
        $first = $this->booking('2026-08-08 21:00');
        $second = $this->booking('2026-08-08 21:20');
        // The free 21:40–22:00 absorbs twenty minutes of the delay.
        $afterGap = $this->booking('2026-08-08 22:00');

        $component = Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->call('pickMinutes', 60)
            ->call('confirm')
            ->assertSet('confirming', true)
            ->assertSee(__('app.delay.do_it'));

        // Nothing moves on the first press.
        $this->assertSame('2026-08-08 21:00', $this->startOf($first));

        $component->call('delay')
            ->assertSet('failed', false)
            ->assertSet('notice', trans_choice('booking.delay.delayed', 3))
            ->assertSee($afterGap->patient->name)
            ->assertSee(__('app.delay.messages_none'));

        $this->assertSame('2026-08-08 22:00', $this->startOf($first));
        $this->assertSame('2026-08-08 22:20', $this->startOf($second));
        $this->assertSame('2026-08-08 22:40', $this->startOf($afterGap));
        $this->assertSame([60, 60, 40], array_column(array_column($component->get('result')['items'], 'delay'), 'minutes'));
        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_a_delay_can_start_later_in_the_list(): void
    {
        $earlier = $this->booking('2026-08-08 21:00');
        $from = $this->booking('2026-08-08 21:20');

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->call('pickFrom', $from->id)
            ->call('pickMinutes', 15)
            ->call('delay');

        $this->assertSame('2026-08-08 21:00', $this->startOf($earlier));
        $this->assertSame('2026-08-08 21:35', $this->startOf($from));
    }

    public function test_a_refusal_is_shown_on_the_page_and_moves_nobody(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 02:30', 'Africa/Cairo'));
        $from = $this->booking('2026-08-09 04:40');
        $last = $this->booking('2026-08-09 05:00');

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->call('pickMinutes', 60)
            ->call('delay')
            ->assertSet('failed', true)
            ->assertSet('notice', __('booking.delay.past_end_of_day', ['time' => '06:00']))
            ->assertSet('result', null);

        $this->assertSame('2026-08-09 04:40', $this->startOf($from));
        $this->assertSame('2026-08-09 05:00', $this->startOf($last));
    }

    public function test_a_patient_who_went_in_meanwhile_is_no_longer_the_pick(): void
    {
        $first = $this->booking('2026-08-08 21:00');
        $next = $this->booking('2026-08-08 21:20');

        $component = Livewire::actingAs($this->owner)->test(Delay::class)->assertSet('from', $first->id);

        $first->update(['status' => BookingStatus::WITH_DOCTOR]);

        $component->call('dismiss')->assertSet('from', $next->id);
    }

    public function test_after_a_delay_the_form_comes_back_for_another(): void
    {
        $first = $this->booking('2026-08-08 21:00');

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->call('delay')
            ->call('again')
            ->assertSet('result', null)
            ->assertSet('from', $first->id)
            ->assertSee(__('app.delay.how_long'));
    }

    public function test_with_nobody_waiting_it_says_so(): void
    {
        $this->booking('2026-08-08 21:00', BookingStatus::DONE);

        Livewire::actingAs($this->owner)
            ->test(Delay::class)
            ->assertSee(__('app.delay.nobody'))
            ->assertDontSee(__('app.delay.how_long'));
    }
}
