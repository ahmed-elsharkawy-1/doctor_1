<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Events\SlotsChanged;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\User;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Support\DelayLabel;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * "تأخير طارئ": the patient picked moves by the whole delay, everyone after
 * only as far as they must, and a gap absorbs part of it.
 */
class EmergencyDelayTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 20:30', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['timezone' => 'Africa/Cairo']);
        $this->seed(MessageTemplateSeeder::class);

        Sanctum::actingAs($this->secretary, ['queue.manage']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Saturday evening's booking whose real time is $at (it may fall after midnight). */
    private function booking(string $at, BookingStatus $status = BookingStatus::BOOKED, string $date = '2026-08-08', bool $optedIn = true): Booking
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'whatsapp_opt_in_at' => $optedIn ? now() : null,
        ]);

        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse($at), 20)
            ->create(['status' => $status, 'visit_date' => $date, 'patient_id' => $patient->id]);
    }

    private function delay(Booking $from, int $minutes = 60)
    {
        return $this->postJson(route('api.v1.emergency'), [
            'action' => 'delay',
            'booking_id' => $from->id,
            'delay_minutes' => $minutes,
        ]);
    }

    private function startOf(Booking $booking): string
    {
        return $booking->fresh()->start_at->format('Y-m-d H:i');
    }

    /*
    |--------------------------------------------------------------------------
    | The cascade
    |--------------------------------------------------------------------------
    */

    public function test_the_delay_cascades_and_a_gap_absorbs_part_of_it(): void
    {
        $first = $this->booking('2026-08-08 21:00');
        $second = $this->booking('2026-08-08 21:20');
        $third = $this->booking('2026-08-08 21:40');
        // 22:00–22:30 is free.
        $fourth = $this->booking('2026-08-08 22:30');
        $fifth = $this->booking('2026-08-08 22:50');
        // 23:10–23:40 is free, and swallows the rest.
        $last = $this->booking('2026-08-08 23:40');

        $response = $this->delay($first, 60)
            ->assertOk()
            ->assertJsonPath('data.moved_count', 5)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('message', trans_choice('booking.delay.delayed', 5));

        $this->assertSame('2026-08-08 22:00', $this->startOf($first));
        $this->assertSame('2026-08-08 22:20', $this->startOf($second));
        $this->assertSame('2026-08-08 22:40', $this->startOf($third));
        $this->assertSame('2026-08-08 23:00', $this->startOf($fourth));
        $this->assertSame('2026-08-08 23:20', $this->startOf($fifth));
        $this->assertSame('2026-08-08 23:40', $this->startOf($last));
        $this->assertSame('2026-08-08 23:40', $fifth->fresh()->end_at->format('Y-m-d H:i'));

        $items = $response->json('data.items');
        $this->assertSame([$first->id, $second->id, $third->id, $fourth->id, $fifth->id], array_column($items, 'booking_id'));
        $this->assertSame([60, 60, 60, 30, 30], array_column(array_column($items, 'delay'), 'minutes'));
        $this->assertSame(DelayLabel::for(30, 'ar'), $items[3]['delay']['label']);
        $this->assertSame('22:30', $items[3]['previous_start_time']['value']);
        $this->assertSame('23:00', $items[3]['start_time']['value']);
    }

    public function test_the_last_patient_of_a_full_evening_moves_by_the_whole_delay(): void
    {
        $bookings = collect(['21:00', '21:20', '21:40', '22:00'])
            ->map(fn (string $time) => $this->booking("2026-08-08 {$time}"));

        $this->delay($bookings->first(), 90)->assertOk()->assertJsonPath('data.moved_count', 4);

        $this->assertSame('2026-08-08 23:30', $this->startOf($bookings->last()));
    }

    public function test_patients_seen_or_with_the_doctor_stay_put_and_their_time_counts_as_free(): void
    {
        $from = $this->booking('2026-08-08 21:00');
        $withDoctor = $this->booking('2026-08-08 21:20', BookingStatus::WITH_DOCTOR);
        $done = $this->booking('2026-08-08 21:40', BookingStatus::DONE);
        $after = $this->booking('2026-08-08 22:00');

        $this->delay($from, 60)->assertOk()->assertJsonPath('data.moved_count', 2);

        $this->assertSame('2026-08-08 22:00', $this->startOf($from));
        $this->assertSame('2026-08-08 21:20', $this->startOf($withDoctor));
        $this->assertSame('2026-08-08 21:40', $this->startOf($done));
        // Straight after the starting patient's new end (22:20): the two in
        // between neither moved nor pushed anyone.
        $this->assertSame('2026-08-08 22:20', $this->startOf($after));
    }

    public function test_a_patient_already_in_the_clinic_moves_too(): void
    {
        $from = $this->booking('2026-08-08 21:00');
        $arrived = $this->booking('2026-08-08 21:20', BookingStatus::ARRIVED);

        $this->delay($from, 30)->assertOk();

        $this->assertSame('2026-08-08 21:50', $this->startOf($arrived));
    }

    public function test_earlier_patients_cancelled_ones_and_emergencies_are_untouched(): void
    {
        $earlier = $this->booking('2026-08-08 20:40');
        $from = $this->booking('2026-08-08 21:00');
        $cancelled = $this->booking('2026-08-08 21:20', BookingStatus::CANCELLED);
        $emergency = $this->booking('2026-08-08 21:30');
        $emergency->update(['booking_kind' => BookingKind::EMERGENCY]);

        $this->delay($from, 60)->assertOk()->assertJsonPath('data.moved_count', 1);

        $this->assertSame('2026-08-08 20:40', $this->startOf($earlier));
        $this->assertSame('2026-08-08 21:20', $this->startOf($cancelled));
        $this->assertSame('2026-08-08 21:30', $this->startOf($emergency));
    }

    public function test_tomorrows_bookings_are_untouched(): void
    {
        $from = $this->booking('2026-08-08 21:00');
        $tomorrow = $this->booking('2026-08-09 21:20', date: '2026-08-09');

        $this->delay($from, 60)->assertOk();

        $this->assertSame('2026-08-09 21:20', $this->startOf($tomorrow));
    }

    public function test_a_patient_pushed_past_midnight_stays_on_this_evenings_list(): void
    {
        $from = $this->booking('2026-08-08 23:30');

        $this->delay($from, 60)
            ->assertOk()
            ->assertJsonPath('data.items.0.after_midnight', true);

        $booking = $from->fresh();
        $this->assertSame('2026-08-09 00:30', $booking->start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-08', $booking->visit_date->toDateString());
    }

    public function test_a_delay_past_the_end_of_the_clinic_day_is_refused_and_moves_nobody(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 02:30', 'Africa/Cairo'));
        $from = $this->booking('2026-08-09 04:40');
        $last = $this->booking('2026-08-09 05:00');

        // The first would end at 06:00 exactly, which is fine; the second
        // would end at 06:20, which refuses the whole delay.
        $this->delay($from, 60)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'DELAY_PAST_END_OF_DAY')
            ->assertJsonPath('error.details.booking_id', $last->id)
            ->assertJsonPath('message', __('booking.delay.past_end_of_day', ['time' => '06:00']));

        $this->assertSame('2026-08-09 04:40', $this->startOf($from));
        $this->assertSame('2026-08-09 05:00', $this->startOf($last));

        $this->delay($from, 30)->assertOk();
        $this->assertSame('2026-08-09 05:30', $this->startOf($last));
    }

    public function test_free_time_is_announced_to_everyone_watching_the_day(): void
    {
        Event::fake([SlotsChanged::class]);
        $from = $this->booking('2026-08-08 21:00');

        $this->delay($from)->assertOk();

        Event::assertDispatched(SlotsChanged::class, fn (SlotsChanged $event) => $event->date === '2026-08-08'
            && $event->clinic->is($this->clinic));
    }

    /*
    |--------------------------------------------------------------------------
    | Repeats
    |--------------------------------------------------------------------------
    */

    public function test_the_same_tap_twice_moves_everyone_once(): void
    {
        $from = $this->booking('2026-08-08 21:00');
        $next = $this->booking('2026-08-08 21:20');

        $this->delay($from, 30)->assertOk();
        $this->delay($from, 30)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.moved_count', 2)
            ->assertJsonPath('data.items.0.delay.minutes', 30);

        $this->assertSame('2026-08-08 21:30', $this->startOf($from));
        $this->assertSame('2026-08-08 21:50', $this->startOf($next));
    }

    public function test_a_second_delay_later_on_moves_them_again(): void
    {
        $from = $this->booking('2026-08-08 21:00');

        $this->delay($from, 30)->assertOk();
        $this->travel(config('clinic.delay.replay_seconds') + 1)->seconds();
        $this->delay($from, 30)->assertOk()->assertJsonPath('data.replayed', false);

        $this->assertSame('2026-08-08 22:00', $this->startOf($from));
    }

    /*
    |--------------------------------------------------------------------------
    | Who can start one
    |--------------------------------------------------------------------------
    */

    public function test_a_delay_must_start_from_a_patient_still_waiting(): void
    {
        foreach ([BookingStatus::WITH_DOCTOR, BookingStatus::DONE, BookingStatus::CANCELLED, BookingStatus::NO_SHOW] as $status) {
            $this->delay($this->booking('2026-08-08 21:00', $status))
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'BOOKING_NOT_DELAYABLE')
                ->assertJsonPath('error.details.reason', 'not_waiting')
                ->assertJsonPath('message', __('booking.delay.not_delayable'));
        }

        $emergency = $this->booking('2026-08-08 21:00');
        $emergency->update(['booking_kind' => BookingKind::EMERGENCY, 'start_at' => null, 'end_at' => null]);

        $this->delay($emergency)->assertStatus(400)->assertJsonPath('error.details.reason', 'not_waiting');
    }

    public function test_only_todays_bookings_can_start_a_delay(): void
    {
        $tomorrow = $this->booking('2026-08-09 21:00', date: '2026-08-09');

        $this->delay($tomorrow)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BOOKING_NOT_DELAYABLE')
            ->assertJsonPath('error.details.reason', 'not_today');

        $this->assertSame('2026-08-09 21:00', $this->startOf($tomorrow));
    }

    public function test_another_clinics_booking_is_not_found(): void
    {
        $theirs = Booking::factory()->forClinic($this->otherClinic())
            ->at(Carbon::parse('2026-08-08 21:00'))
            ->create();

        $this->delay($theirs)->assertNotFound()->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');

        $this->assertSame('2026-08-08 21:00', $this->startOf($theirs));
    }

    public function test_only_the_offered_delays_are_accepted(): void
    {
        $from = $this->booking('2026-08-08 21:00');

        $this->delay($from, 45)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['delay_minutes']]]);
        $this->postJson(route('api.v1.emergency'), ['action' => 'cancel', 'booking_id' => $from->id, 'delay_minutes' => 30])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['action']]]);

        $this->assertSame('2026-08-08 21:00', $this->startOf($from));
    }

    public function test_it_needs_the_queue_ability(): void
    {
        $from = $this->booking('2026-08-08 21:00');
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->delay($from)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN_ROLE');
        $this->getJson(route('api.v1.emergency.delay-options'))->assertForbidden();

        $this->assertSame('2026-08-08 21:00', $this->startOf($from));
    }

    public function test_the_options_come_worded(): void
    {
        $items = $this->getJson(route('api.v1.emergency.delay-options'))
            ->assertOk()
            ->assertJsonPath('message', __('booking.delay.options_loaded'))
            ->json('data.items');

        $this->assertSame(config('clinic.delay.options'), array_column($items, 'minutes'));
        $this->assertSame(DelayLabel::for(90, 'ar'), collect($items)->firstWhere('minutes', 90)['label']);
    }

    /*
    |--------------------------------------------------------------------------
    | The messages
    |--------------------------------------------------------------------------
    */

    public function test_nothing_is_sent_until_the_template_is_approved(): void
    {
        $from = $this->booking('2026-08-08 21:00');

        $this->delay($from)
            ->assertOk()
            ->assertJsonPath('data.messaging.sent_count', 0)
            ->assertJsonPath('data.messaging.skipped.0.reason', 'template_not_ready');

        $this->assertSame(0, OutboundMessage::count());
    }

    /** In the approved template's order: patient, date, new time, doctor, clinic phone. */
    public function test_once_switched_on_each_moved_patient_is_told_their_new_time(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::DELAYED_KEY)->update(['is_active' => true]);
        $this->clinic->update(['phone' => '01012345678']);
        $from = $this->booking('2026-08-08 21:00');
        $afterGap = $this->booking('2026-08-08 21:30');
        $notOptedIn = $this->booking('2026-08-08 22:10', optedIn: false);

        $this->delay($from, 60)
            ->assertOk()
            ->assertJsonPath('data.messaging.sent_count', 2)
            ->assertJsonPath('data.messaging.skipped.0.booking_id', $notOptedIn->id)
            ->assertJsonPath('data.messaging.skipped.0.reason', 'whatsapp_not_opted_in');

        $first = OutboundMessage::where('booking_id', $from->id)->firstOrFail();
        $this->assertSame(WhatsAppMessagingService::DELAYED_KEY, $first->template_key);
        $this->assertCount(5, $first->variables);
        $this->assertSame($from->patient->name, $first->variables[0]);
        $this->assertSame('8 أغسطس 2026', $first->variables[1]);
        $this->assertSame('10:00 '.__('schedule.pm'), $first->variables[2]);
        $this->assertStringContainsString($this->clinic->doctor->name, 'د. '.$first->variables[3]);
        $this->assertSame('+201012345678', $first->variables[4]);
        $this->assertStringContainsString('booking/'.$from->tracking_token, (string) $first->button_suffix);

        // Its own new time, not the first patient's.
        $second = OutboundMessage::where('booking_id', $afterGap->id)->firstOrFail();
        $this->assertSame('10:20 '.__('schedule.pm'), $second->variables[2]);
    }

    public function test_with_whatsapp_off_the_times_still_move(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::DELAYED_KEY)->update(['is_active' => true]);
        $this->clinic->update(['whatsapp_enabled' => false]);
        $from = $this->booking('2026-08-08 21:00');

        $this->delay($from)
            ->assertOk()
            ->assertJsonPath('data.messaging.whatsapp_enabled', false)
            ->assertJsonPath('data.messaging.skipped.0.reason', 'whatsapp_disabled');

        $this->assertSame('2026-08-08 22:00', $this->startOf($from));
        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_a_repeat_sends_nothing_more(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::DELAYED_KEY)->update(['is_active' => true]);
        $from = $this->booking('2026-08-08 21:00');

        $this->delay($from)->assertOk();
        $this->delay($from)->assertOk()->assertJsonPath('data.messaging.sent_count', 1);

        $this->assertSame(1, OutboundMessage::count());
    }

    /** A per-booking message: offering it as a broadcast would send it with no delay in it. */
    public function test_the_delay_message_is_never_offered_as_a_broadcast(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::DELAYED_KEY)->update(['is_active' => true]);

        $keys = array_column($this->getJson(route('api.v1.message-templates.index'))->json('data.items'), 'key');

        $this->assertNotContains(WhatsAppMessagingService::DELAYED_KEY, $keys);
    }
}
