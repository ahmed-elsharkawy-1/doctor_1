<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\PatientLocation;
use App\Enums\PatientResponse;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\QueuePositionService;
use App\Services\V1\Queue\QueueService;
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

    /** @return list<string> */
    private function names(): array
    {
        return array_column(array_column($this->calendar()['bookings']['2026-08-08'], 'patient'), 'name');
    }

    private function emergency(string $name, ?string $arrivedAt): Booking
    {
        $arrived = $arrivedAt === null ? null : Carbon::parse('2026-08-08 '.$arrivedAt, 'Africa/Cairo');

        return Booking::factory()
            ->forClinic($this->clinic)
            ->create([
                'patient_id' => Patient::factory()->create([
                    'clinic_id' => $this->clinic->id,
                    'name' => $name,
                ])->id,
                'visit_date' => $this->today->toDateString(),
                'start_at' => null,
                'end_at' => null,
                'status' => $arrived === null ? BookingStatus::BOOKED : BookingStatus::ARRIVED,
                'booking_kind' => BookingKind::EMERGENCY,
                'patient_location' => $arrived === null ? PatientLocation::ON_WAY : PatientLocation::INSIDE_CLINIC,
                'arrived_at' => $arrived,
                'queue_entered_at' => $arrived,
            ]);
    }

    /**
     * The doctor calls by appointment, so the list does too: a later patient
     * who turns up early does not climb above an earlier one still on the
     * way — the same order the patient's tracking page counts by.
     */
    public function test_waiting_cards_sort_by_appointment_not_by_arrival(): void
    {
        $late = $this->booking('11:00', 'ج');
        $this->booking('09:00', 'أ');
        $this->booking('10:00', 'ب');

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:30:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.status', $late), ['to' => 'arrived'])->assertOk();

        $this->assertSame(['أ', 'ب', 'ج'], $this->names());
    }

    /** Arriving late does not cost a patient their place either. */
    public function test_an_earlier_patient_who_arrives_last_keeps_their_place(): void
    {
        $early = $this->booking('09:00', 'أ');
        $second = $this->booking('09:10', 'ب');
        $third = $this->booking('09:20', 'ج');

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:50:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.status', $third), ['to' => 'arrived'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $second), ['to' => 'arrived'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-08-08 09:05:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.status', $early), ['to' => 'arrived'])->assertOk();

        $this->assertSame(['أ', 'ب', 'ج'], $this->names());
    }

    /** Two bookings on one time keep the order they were made in. */
    public function test_a_shared_time_goes_to_whoever_booked_first(): void
    {
        $this->booking('09:00', 'أ');
        $second = $this->booking('09:00', 'ب');

        $this->postJson(route('api.v1.bookings.status', $second), ['to' => 'arrived'])->assertOk();

        $this->assertSame(['أ', 'ب'], $this->names());
    }

    /**
     * With the doctor on top, then the line, then the day's finished cards —
     * done, cancelled, no-show.
     */
    public function test_the_room_comes_first_and_finished_cards_last(): void
    {
        $inside = $this->booking('10:00', 'بالداخل');
        $done = $this->booking('09:00', 'انتهى');
        $cancelled = $this->booking('09:10', 'ملغي');
        $missed = $this->booking('09:20', 'لم يحضر');
        $this->booking('09:30', 'منتظر');

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:00:00', 'Africa/Cairo'));

        foreach (['arrived', 'with_doctor', 'done'] as $to) {
            $this->postJson(route('api.v1.bookings.status', $done), ['to' => $to])->assertOk();
        }

        $this->postJson(route('api.v1.bookings.cancel', $cancelled), ['reason' => 'patient_cancelled'])->assertOk();
        $this->postJson(route('api.v1.bookings.status', $missed), ['to' => 'no_show'])->assertOk();

        foreach (['arrived', 'with_doctor'] as $to) {
            $this->postJson(route('api.v1.bookings.status', $inside), ['to' => $to])->assertOk();
        }

        $this->assertSame(['بالداخل', 'منتظر', 'انتهى', 'ملغي', 'لم يحضر'], $this->names());
    }

    /**
     * Emergencies stay where the secretary sees them first: those in the
     * clinic by when they arrived, then those still on the way, then everyone
     * else by appointment.
     */
    public function test_emergencies_head_the_line_arrived_ones_first(): void
    {
        $this->booking('09:00', 'عادي');
        $this->emergency('طارئ في الطريق', null);
        $this->emergency('طارئ وصل ثانيا', '09:20');
        $this->emergency('طارئ وصل أولا', '09:10');

        $this->assertSame(['طارئ وصل أولا', 'طارئ وصل ثانيا', 'طارئ في الطريق', 'عادي'], $this->names());
    }

    /**
     * The list and the tracking page agree: every waiting normal patient sits
     * below exactly the patients the tracking page tells them are ahead —
     * plus any emergency still on the way, which is listed near the top for
     * the secretary but not yet counted for the patient.
     */
    public function test_the_list_agrees_with_every_patients_tracking_number(): void
    {
        $bookings = collect([
            $this->booking('09:00', 'أ'),
            $this->booking('09:10', 'ب'),
            $this->booking('09:20', 'ج'),
            $this->booking('09:30', 'د'),
            $this->booking('09:40', 'ه'),
        ]);
        $this->emergency('طارئ في الطريق', null);
        $this->emergency('طارئ وصل', '09:05');

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:00:00', 'Africa/Cairo'));

        foreach ([3, 4, 1] as $index) {
            $this->postJson(route('api.v1.bookings.status', $bookings[$index]), ['to' => 'arrived'])->assertOk();
        }

        foreach (['arrived', 'with_doctor'] as $to) {
            $this->postJson(route('api.v1.bookings.status', $bookings[0]), ['to' => $to])->assertOk();
        }

        $cards = $this->calendar()['bookings']['2026-08-08'];
        $waiting = array_values(array_filter(
            $cards,
            fn (array $card): bool => in_array($card['status']['value'], ['booked', 'arrived'], true),
        ));
        $onTheWay = count(array_filter(
            $waiting,
            fn (array $card): bool => $card['booking_kind']['value'] === 'emergency' && $card['status']['value'] === 'booked',
        ));

        $positions = app(QueuePositionService::class);

        foreach ($waiting as $index => $card) {
            if ($card['booking_kind']['value'] !== 'normal') {
                continue;
            }

            $position = $positions->for(Booking::findOrFail($card['id']));

            $this->assertSame($index - $onTheWay, $position->ahead, $card['patient']['name']);
        }

        $this->assertSame('أ', $cards[0]['patient']['name']);
    }

    /** Postponing reads the same line, so it lists in the same order. */
    public function test_the_postpone_list_follows_the_same_order(): void
    {
        $late = $this->booking('11:00', 'ج');
        $this->booking('09:00', 'أ');

        $this->postJson(route('api.v1.bookings.status', $late), ['to' => 'arrived'])->assertOk();

        $names = array_column(array_column(
            app(QueueService::class)->postponeCandidates($this->clinic, $this->today)->all(),
            'patient',
        ), 'name');

        $this->assertSame(['أ', 'ج'], $names);
    }

    /** The home screen's first five are the next five the doctor will call. */
    public function test_home_lists_the_next_patients_by_appointment(): void
    {
        $late = $this->booking('11:00', 'ج');
        $this->booking('09:00', 'أ');

        $this->postJson(route('api.v1.bookings.status', $late), ['to' => 'arrived'])->assertOk();

        $data = $this->getJson(route('api.v1.home'))->assertOk()->json('data');

        $this->assertSame(['أ', 'ج'], array_column(array_column($data['upcoming'], 'patient'), 'name'));
    }

    public function test_emergency_arrived_patients_sort_before_normal_arrived_patients(): void
    {
        $normal = $this->booking('09:00', 'عادي');
        $emergency = Booking::factory()
            ->forClinic($this->clinic)
            ->create([
                'patient_id' => Patient::factory()->create([
                    'clinic_id' => $this->clinic->id,
                    'name' => 'طارئ',
                ])->id,
                'visit_date' => $this->today->toDateString(),
                'start_at' => null,
                'end_at' => null,
                'status' => BookingStatus::ARRIVED,
                'booking_kind' => BookingKind::EMERGENCY,
                'patient_location' => PatientLocation::INSIDE_CLINIC,
                'arrived_at' => Carbon::parse('2026-08-08 09:10:00', 'Africa/Cairo'),
                'queue_entered_at' => Carbon::parse('2026-08-08 09:10:00', 'Africa/Cairo'),
            ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:05:00', 'Africa/Cairo'));
        $this->postJson(route('api.v1.bookings.status', $normal), ['to' => 'arrived'])->assertOk();

        $names = array_column(array_column($this->calendar()['bookings']['2026-08-08'], 'patient'), 'name');

        $this->assertSame(['طارئ', 'عادي'], $names);
        $this->assertNotNull($emergency->refresh()->queue_entered_at);
    }

    public function test_cards_have_next_status_and_no_queue_position(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        $card = collect($this->calendar()['bookings']['2026-08-08'])->firstWhere('id', $booking->id);

        $this->assertSame('arrived', $card['next_status']['value']);
        $this->assertSame(['call', 'confirm_attendance', 'whatsapp', 'edit', 'no_show', 'cancel'], $card['available_actions']);
        $this->assertArrayNotHasKey('queue_position', $card);
    }

    public function test_a_card_reports_the_reminder_and_the_patients_answer(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        foreach ([
            [WhatsAppMessagingService::REMINDER_KEY, 'sent'],
            [WhatsAppMessagingService::DAILY_REMINDER_KEY, 'delivered'],
        ] as [$templateKey, $status]) {
            OutboundMessage::create([
                'clinic_id' => $this->clinic->id,
                'patient_id' => $booking->patient_id,
                'booking_id' => $booking->id,
                'template_key' => $templateKey,
                'type' => 'template',
                'rendered_body' => 'reminder',
                'status' => $status,
                'provider_message_id' => 'wamid.REMINDER',
            ]);
        }

        $booking->update(['patient_response' => PatientResponse::CONFIRMED]);

        $card = collect($this->calendar()['bookings']['2026-08-08'])->firstWhere('id', $booking->id);

        // Each reminder is reported on its own.
        $this->assertSame('sent', $card['reminder_status']['value']);
        $this->assertSame(__('app.queue.reminder.sent'), $card['reminder_status']['display']);
        $this->assertSame('sent', $card['daily_reminder_status']['value']);
        $this->assertSame(__('app.queue.reminder.sent'), $card['daily_reminder_status']['display']);
        $this->assertSame('confirmed', $card['patient_response']['value']);
        $this->assertSame(__('booking.patient_response.confirmed'), $card['patient_response']['display']);
    }

    public function test_a_card_without_a_reminder_says_not_sent(): void
    {
        $booking = $this->booking('09:00', 'سارة');

        $card = collect($this->calendar()['bookings']['2026-08-08'])->firstWhere('id', $booking->id);

        $this->assertSame('not_sent', $card['reminder_status']['value']);
        $this->assertSame('not_sent', $card['daily_reminder_status']['value']);
        $this->assertNull($card['patient_response']);
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
        $this->assertSame(4, $counts['normal_count']);
        $this->assertSame(0, $counts['emergency_count']);
        $this->assertSame(1, $counts['booked']);
        $this->assertSame(1, $counts['done']);
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(1, $counts['no_show']);
    }

    /**
     * The home list is everyone still in today's queue, in queue order — not
     * whoever happens to start later. A 09:00 booking nobody has resolved by
     * 09:30 is precisely when it needs to be on screen, not the moment it
     * drops off one.
     */
    public function test_home_returns_today_counts_and_everyone_still_in_the_queue(): void
    {
        $this->booking('09:00', 'أ');
        $this->booking('10:00', 'ب');

        Carbon::setTestNow(Carbon::parse('2026-08-08 09:30:00', 'Africa/Cairo'));

        $data = $this->getJson(route('api.v1.home'))->assertOk()->json('data');

        $this->assertSame(2, $data['today']['counts']['total']);
        $this->assertSame(2, $data['today']['counts']['normal_count']);
        $this->assertSame(['أ', 'ب'], array_column(array_column($data['upcoming'], 'patient'), 'name'));
    }

    public function test_home_drops_a_booking_once_it_leaves_the_queue(): void
    {
        $seen = $this->booking('09:00', 'أ');
        $this->booking('10:00', 'ب');

        foreach (['arrived', 'with_doctor', 'done'] as $to) {
            $this->postJson(route('api.v1.bookings.status', $seen), ['to' => $to])->assertOk();
        }

        $data = $this->getJson(route('api.v1.home'))->assertOk()->json('data');

        $this->assertSame(['ب'], array_column(array_column($data['upcoming'], 'patient'), 'name'));
    }

    /**
     * The home screen is today. It used to match on `start_at >= now` with no
     * upper bound, so a booking days away sat under a row of zeroes; another
     * day belongs to the calendar.
     */
    public function test_home_never_shows_another_day(): void
    {
        $patient = Patient::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'بكرة']);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at($this->today->copy()->addDays(2)->setTime(9, 0))
            ->create(['patient_id' => $patient->id]);

        $data = $this->getJson(route('api.v1.home'))->assertOk()->json('data');

        $this->assertSame(0, $data['today']['counts']['total']);
        $this->assertSame([], $data['upcoming']);
    }

    public function test_another_clinics_bookings_are_never_visible(): void
    {
        Booking::factory()->forClinic($this->otherClinic())
            ->at($this->today->copy()->setTime(9, 0))->create();

        $this->assertSame([], $this->calendar()['bookings']);
    }
}
