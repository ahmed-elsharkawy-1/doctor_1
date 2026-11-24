<?php

namespace Tests\Feature\Statistics;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Enums\PatientResponse;
use App\Enums\PatientResponseSource;
use App\Models\Booking;
use App\Models\BookingLinkOpen;
use App\Models\BookingReview;
use App\Models\Clinic;
use App\Models\ClinicHoliday;
use App\Models\ClinicSchedulePeriod;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Statistics\ClinicStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The Clinic Statistics numbers: one clinic only, nothing invented where the
 * data is missing, and "Insufficient data" below the minimum sample.
 */
class ClinicStatisticsServiceTest extends TestCase
{
    use InteractsWithClinic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        config(['clinic.statistics.min_sample' => 2, 'clinic.statistics.on_time_tolerance_minutes' => 15]);
        // created_at, sent_at and opened_at are the application's clock —
        // Africa/Cairo here as in production — so the times below are Cairo.
        Carbon::setTestNow(Carbon::parse('2026-11-30 14:00:00', 'Africa/Cairo'));
    }

    private function stats(?Clinic $clinic = null, string $from = '2026-11-01', string $to = '2026-11-30'): array
    {
        $result = app(ClinicStatisticsService::class)->for($clinic ?? $this->clinic, Carbon::parse($from), Carbon::parse($to));

        return ['cards' => $result->cards, 'comments' => $result->comments, 'result' => $result];
    }

    private function booking(string $start, array $attributes = [], ?Clinic $clinic = null): Booking
    {
        return Booking::factory()
            ->forClinic($clinic ?? $this->clinic)
            ->at(Carbon::parse($start), 30)
            ->create($attributes + ['price' => 200]);
    }

    private function reminder(Booking $booking, string $sentAt, string $status = 'delivered', string $key = WhatsAppMessagingService::DAILY_REMINDER_KEY): OutboundMessage
    {
        return OutboundMessage::create([
            'clinic_id' => $booking->clinic_id,
            'patient_id' => $booking->patient_id,
            'booking_id' => $booking->id,
            'template_key' => $key,
            'rendered_body' => 'reminder',
            'status' => $status,
            'sent_at' => $sentAt,
        ]);
    }

    /*
    | No-show rate
    */

    public function test_no_show_rate_splits_at_the_first_reminder_and_prices_the_drop(): void
    {
        // Before 2026-11-15: 2 no-shows out of 4 (50%).
        $this->booking('2026-11-03 10:00', ['status' => BookingStatus::NO_SHOW]);
        $this->booking('2026-11-04 10:00', ['status' => BookingStatus::NO_SHOW]);
        $this->booking('2026-11-05 10:00', ['status' => BookingStatus::DONE]);
        $this->booking('2026-11-06 10:00', ['status' => BookingStatus::DONE]);

        // From 2026-11-15: 1 no-show out of 4 (25%), fee 200.
        $first = $this->booking('2026-11-15 10:00', ['status' => BookingStatus::DONE]);
        $this->booking('2026-11-16 10:00', ['status' => BookingStatus::DONE]);
        $this->booking('2026-11-17 10:00', ['status' => BookingStatus::DONE]);
        $this->booking('2026-11-18 10:00', ['status' => BookingStatus::NO_SHOW]);

        // A failed reminder earlier does not count as activation.
        $this->reminder($first, '2026-11-01 08:00:00', 'failed');
        $this->reminder($first, '2026-11-15 01:30:00'); // just after midnight, Cairo

        $card = $this->stats()['cards']['no_show'];

        $this->assertSame('2026-11-15', $card['activation_date']);
        $this->assertFalse($card['no_baseline']);
        $this->assertSame(50.0, $card['before']['rate']);
        $this->assertSame(25.0, $card['after']['rate']);
        $this->assertSame(-25.0, $card['change_points']);
        $this->assertSame(200.0, $card['avg_visit_fee']);
        // 25% of 4 visits × 200.
        $this->assertSame(200.0, $card['recovered_revenue']);
    }

    public function test_no_visits_before_activation_means_no_baseline(): void
    {
        $a = $this->booking('2026-11-10 10:00', ['status' => BookingStatus::DONE]);
        $this->booking('2026-11-11 10:00', ['status' => BookingStatus::NO_SHOW]);
        $this->reminder($a, '2026-10-01 08:00:00');

        $stats = $this->stats();
        $card = $stats['cards']['no_show'];

        $this->assertTrue($card['no_baseline']);
        $this->assertNull($card['before']);
        $this->assertSame(50.0, $card['after']['rate']);
        $this->assertNull($card['change_points']);
        $this->assertNull($card['recovered_revenue']);

        $rows = $stats['result']->csvRows();
        $measures = array_column($rows, 4);
        $this->assertContains(__('statistics.measures.after_rate'), $measures);
        $before = array_values(array_filter(
            $rows,
            fn (array $row) => $row[4] === __('statistics.measures.before_rate'),
        ));
        $this->assertSame(__('statistics.no_baseline'), $before[0][5]);
    }

    public function test_a_clinic_without_reminders_is_not_activated(): void
    {
        $this->booking('2026-11-10 10:00', ['status' => BookingStatus::DONE]);
        $this->booking('2026-11-11 10:00', ['status' => BookingStatus::NO_SHOW]);

        $stats = $this->stats();
        $card = $stats['cards']['no_show'];

        $this->assertFalse($card['activated']);
        $this->assertNull($card['after']);
        $this->assertSame(50.0, $card['before']['rate']);
        $this->assertNull($card['recovered_revenue']);
        $this->assertContains(
            __('statistics.measures.overall_rate'),
            array_column($stats['result']->csvRows(), 4),
        );
    }

    public function test_below_the_minimum_sample_there_is_no_value(): void
    {
        config(['clinic.statistics.min_sample' => 20]);
        $this->booking('2026-11-10 10:00', ['status' => BookingStatus::NO_SHOW]);

        $card = $this->stats()['cards']['no_show'];

        $this->assertSame('insufficient', $card['state']);
        $this->assertNull($card['before']['rate']);
    }

    public function test_another_clinics_data_never_counts(): void
    {
        $other = $this->otherClinic();
        $this->booking('2026-11-10 10:00', ['status' => BookingStatus::NO_SHOW], $other);
        $this->booking('2026-11-11 10:00', ['status' => BookingStatus::NO_SHOW], $other);
        $this->reminder(Booking::first(), '2026-11-01 08:00:00');

        $cards = $this->stats()['cards'];

        $this->assertSame('no_data', $cards['no_show']['state']);
        $this->assertFalse($cards['no_show']['activated']);
        $this->assertSame(0, $cards['outside_hours']['sample']);
        $this->assertSame(0, $cards['whatsapp']['sample']);
    }

    /*
    | Outside working hours and holidays
    */

    public function test_bookings_made_outside_hours_or_on_holidays_are_counted(): void
    {
        // Sunday 2026-11-08, open 10:00–14:00 Cairo.
        $sunday = $this->clinic->schedules()->where('day_of_week', DayOfWeek::SUNDAY)->first();
        $sunday->update(['is_open' => true]);
        ClinicSchedulePeriod::create(['clinic_schedule_id' => $sunday->id, 'start_time' => '10:00', 'end_time' => '14:00']);
        ClinicHoliday::create(['clinic_id' => $this->clinic->id, 'date' => '2026-11-15']);

        $at = fn (string $made) => $this->booking('2026-11-20 10:00')->forceFill(['created_at' => $made])->save();

        $at('2026-11-08 12:00:00'); // open
        $at('2026-11-08 20:00:00'); // closed
        $at('2026-11-09 12:00:00'); // Monday, closed all day
        $at('2026-11-15 12:00:00'); // Sunday, but a holiday

        $card = $this->stats()['cards']['outside_hours'];

        $this->assertSame(4, $card['sample']);
        $this->assertSame(3, $card['count']);
        $this->assertSame(2, $card['outside_hours']);
        $this->assertSame(1, $card['on_holidays']);
        $this->assertSame(75.0, $card['percent']);
    }

    /*
    | Cancelled slots filled again
    */

    public function test_a_cancelled_slot_counts_as_refilled_only_by_another_patient_before_its_time(): void
    {
        // All Cairo: the slot, the cancel and when the next booking was made.
        $filled = $this->booking('2026-11-20 10:00', ['status' => BookingStatus::CANCELLED, 'cancel_reason' => CancelReason::PATIENT_CANCELLED, 'cancelled_at' => '2026-11-19 09:00:00']);
        $this->booking('2026-11-20 10:00')->forceFill(['created_at' => '2026-11-19 10:00:00'])->save(); // 60 min later

        $samePatient = $this->booking('2026-11-21 10:00', ['status' => BookingStatus::CANCELLED, 'cancel_reason' => CancelReason::PATIENT_CANCELLED, 'cancelled_at' => '2026-11-20 09:00:00']);
        $this->booking('2026-11-21 10:00', ['patient_id' => $samePatient->patient_id])->forceFill(['created_at' => '2026-11-20 11:00:00'])->save();

        // Cancelled by the clinic's day-off: not a slot anyone could take.
        $this->booking('2026-11-22 10:00', ['status' => BookingStatus::CANCELLED, 'cancel_reason' => CancelReason::EMERGENCY, 'cancelled_at' => '2026-11-21 09:00:00']);

        $card = $this->stats()['cards']['rebooked_slots'];

        $this->assertSame(2, $card['cancelled']);
        $this->assertSame(1, $card['refilled']);
        $this->assertSame(50.0, $card['fill_rate']);
        $this->assertNotNull($filled);
    }

    /*
    | Waiting time
    */

    public function test_waiting_time_leaves_out_visits_missing_a_time(): void
    {
        $this->booking('2026-11-10 10:00', ['status' => BookingStatus::DONE, 'arrived_at' => '2026-11-10 10:00', 'called_in_at' => '2026-11-10 10:10']);
        $this->booking('2026-11-10 11:00', ['status' => BookingStatus::DONE, 'arrived_at' => '2026-11-10 11:00', 'called_in_at' => '2026-11-10 11:20']);
        $this->booking('2026-11-10 12:00', ['status' => BookingStatus::DONE, 'arrived_at' => '2026-11-10 12:00', 'called_in_at' => '2026-11-10 12:45']);
        $this->booking('2026-11-10 13:00', ['status' => BookingStatus::DONE, 'arrived_at' => null, 'called_in_at' => '2026-11-10 13:05']);

        $card = $this->stats()['cards']['waiting_time'];

        $this->assertSame(3, $card['sample']);
        $this->assertSame(1, $card['excluded']);
        $this->assertSame(25.0, $card['avg_minutes']);
        $this->assertSame(20.0, $card['median_minutes']);
    }

    /*
    | Queue link opens
    */

    public function test_reopening_the_tracking_link_counts_once(): void
    {
        $opened = $this->booking('2026-11-30 18:00');
        $this->booking('2026-11-30 19:00');

        $this->get($opened->trackingUrl())->assertOk();
        $this->get($opened->trackingUrl())->assertOk();

        $this->assertSame(1, BookingLinkOpen::count());

        $card = $this->stats()['cards']['link_opens'];
        $this->assertSame(2, $card['sample']);
        $this->assertSame(1, $card['opened']);
        $this->assertSame(50.0, $card['percent']);
    }

    public function test_nothing_recorded_yet_means_no_link_open_data(): void
    {
        $this->booking('2026-11-10 10:00')
            ->forceFill(['created_at' => '2026-11-01 09:00:00'])
            ->save();

        $this->assertSame('no_data', $this->stats()['cards']['link_opens']['state']);
    }

    public function test_link_rate_includes_bookings_made_before_the_first_link_was_opened(): void
    {
        $unopened = $this->booking('2026-11-30 18:00')
            ->forceFill(['created_at' => '2026-11-30 09:00:00']);
        $unopened->save();
        $opened = $this->booking('2026-11-30 19:00')
            ->forceFill(['created_at' => '2026-11-30 10:00:00']);
        $opened->save();
        BookingLinkOpen::create([
            'booking_id' => $opened->id,
            'clinic_id' => $opened->clinic_id,
            'opened_at' => '2026-11-30 13:00:00',
        ]);

        $card = $this->stats()['cards']['link_opens'];

        $this->assertSame(2, $card['sample']);
        $this->assertSame(1, $card['opened']);
        $this->assertSame(50.0, $card['percent']);
    }

    /*
    | On-time arrival
    */

    public function test_on_time_counts_every_check_in_against_the_expected_time_and_tolerance(): void
    {
        $made = ['created_at' => '2026-11-01 08:00:00'];
        $this->booking('2026-11-10 10:00', ['arrived_at' => '2026-11-10 09:40'])->forceFill($made)->save(); // early
        $this->booking('2026-11-10 11:00', ['arrived_at' => '2026-11-10 11:15'])->forceFill($made)->save(); // at the limit
        $this->booking('2026-11-10 12:00', ['arrived_at' => '2026-11-10 12:30'])->forceFill($made)->save(); // late
        // Booked at the desk when they walked in.
        $this->booking('2026-11-10 14:00', ['arrived_at' => '2026-11-10 13:00'])->forceFill(['created_at' => '2026-11-10 13:00:00'])->save();

        $card = $this->stats()['cards']['on_time'];

        $this->assertSame(4, $card['sample']);
        $this->assertSame(3, $card['on_time']);
        $this->assertSame(75.0, $card['percent']);
    }

    /*
    | WhatsApp response rate
    */

    public function test_whatsapp_responses_count_only_delivered_visit_day_reminders(): void
    {
        $confirmed = $this->booking('2026-11-10 10:00', ['patient_response' => PatientResponse::CONFIRMED, 'patient_response_source' => PatientResponseSource::WHATSAPP]);
        $cancelled = $this->booking('2026-11-10 11:00', ['patient_response' => PatientResponse::CANCELLED, 'patient_response_source' => PatientResponseSource::WHATSAPP]);
        $byPhone = $this->booking('2026-11-10 12:00', ['patient_response' => PatientResponse::CONFIRMED, 'patient_response_source' => PatientResponseSource::CLINIC]);
        $silent = $this->booking('2026-11-10 13:00');
        $failed = $this->booking('2026-11-10 14:00', ['patient_response' => PatientResponse::CONFIRMED, 'patient_response_source' => PatientResponseSource::WHATSAPP]);

        $this->reminder($confirmed, '2026-11-10 08:00:00', 'read');
        $this->reminder($cancelled, '2026-11-10 08:00:00');
        $this->reminder($byPhone, '2026-11-10 08:00:00');
        $this->reminder($silent, '2026-11-10 08:00:00');
        $this->reminder($failed, '2026-11-10 08:00:00', 'failed');

        $card = $this->stats()['cards']['whatsapp'];

        $this->assertSame(4, $card['sample']);
        $this->assertSame(2, $card['responded']);
        $this->assertSame(50.0, $card['percent']);
        $this->assertSame(1, $card['confirmed']);
        $this->assertSame(1, $card['cancelled']);
    }

    /*
    | Satisfaction and comments
    */

    private function review(Booking $booking, string $rating, ?string $comment, string $at): BookingReview
    {
        return BookingReview::create([
            'booking_id' => $booking->id,
            'clinic_id' => $booking->clinic_id,
            'doctor_id' => $booking->doctor_id,
            'patient_id' => $booking->patient_id,
            'rating' => $rating,
            'comment' => $comment,
            'submitted_at' => $at,
        ]);
    }

    public function test_satisfaction_uses_the_three_levels_and_comments_are_anonymised(): void
    {
        $a = $this->booking('2026-11-10 10:00');
        $a->patient->update(['name' => 'Samira Khalil']);
        $this->review($a, 'very_good', 'Samira here, call me on 01012345678, great doctor', '2026-11-11 10:00');
        $this->review($this->booking('2026-11-10 11:00'), 'good', 'Short wait', '2026-11-12 10:00');
        $this->review($this->booking('2026-11-10 12:00'), 'good', null, '2026-11-12 11:00');
        $this->review($this->booking('2026-11-10 13:00'), 'bad', 'Too slow', '2026-11-13 10:00');
        $this->review($this->booking('2026-11-10 14:00'), 'very_good', 'Outside the range', '2026-10-01 10:00');

        $stats = $this->stats();
        $card = $stats['cards']['satisfaction'];

        $this->assertSame(4, $card['sample']);
        $this->assertSame(75.0, $card['positive_percent']);
        $this->assertSame(['count' => 1, 'percent' => 25.0], $card['levels']['very_good']);
        $this->assertSame(['count' => 2, 'percent' => 50.0], $card['levels']['good']);
        $this->assertSame(['count' => 1, 'percent' => 25.0], $card['levels']['bad']);

        $comments = $stats['comments'];
        $this->assertCount(2, $comments);
        $this->assertSame('Short wait', $comments[0]['comment']);
        $this->assertSame('2026-11-11', $comments[1]['date']);
        $this->assertStringNotContainsString('Samira', $comments[1]['comment']);
        $this->assertStringNotContainsString('01012345678', $comments[1]['comment']);
        $this->assertStringContainsString('great doctor', $comments[1]['comment']);
    }

    public function test_the_csv_has_no_patient_names_or_phones(): void
    {
        $a = $this->booking('2026-11-10 10:00', ['status' => BookingStatus::DONE]);
        $a->patient->update(['name' => 'Samira Khalil', 'phone' => '+201012345678']);
        $this->review($a, 'very_good', 'Lovely, Samira, call +201012345678', '2026-11-11 10:00');

        $csv = json_encode($this->stats()['result']->csvRows(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Samira', $csv);
        $this->assertStringNotContainsString('1012345678', $csv);
        $this->assertStringContainsString('Lovely', $csv);
    }

    public function test_short_patient_names_in_comments_are_also_removed(): void
    {
        $booking = $this->booking('2026-11-10 10:00');
        $booking->patient->update(['name' => 'Li', 'phone' => '01012345678']);
        $this->review($booking, 'good', 'Li had a lovely visit', '2026-11-11 10:00');

        $comment = $this->stats()['comments'][0]['comment'];

        $this->assertSame('••• had a lovely visit', $comment);
        $this->assertStringNotContainsString('01012345678', $comment);
    }

    /*
    | Times saved the way the app saves them — not typed in by hand
    */

    private function madeAt(string $cairo, string $slot, array $attributes = []): Booking
    {
        Carbon::setTestNow(Carbon::parse($cairo, 'Africa/Cairo'));

        return $this->booking($slot, $attributes);
    }

    public function test_a_refill_an_hour_after_the_cancel_reads_as_an_hour(): void
    {
        $this->madeAt('2026-11-20 12:00', '2026-11-24 15:00', [
            'status' => BookingStatus::CANCELLED,
            'cancel_reason' => CancelReason::PATIENT_CANCELLED,
            'cancelled_at' => '2026-11-24 10:00:00',
        ]);
        $this->madeAt('2026-11-24 11:00', '2026-11-24 15:00');
        $this->madeAt('2026-11-23 09:00', '2026-11-25 15:00', [
            'status' => BookingStatus::CANCELLED,
            'cancel_reason' => CancelReason::PATIENT_CANCELLED,
            'cancelled_at' => '2026-11-25 08:00:00',
        ]);
        $this->madeAt('2026-11-25 09:00', '2026-11-25 15:00');

        $card = $this->stats()['cards']['rebooked_slots'];

        $this->assertSame(2, $card['refilled']);
        $this->assertSame(60.0, $card['avg_minutes_to_refill']);
    }

    /** Taken 90 minutes before its time: still before its time, so refilled. */
    public function test_a_refill_shortly_before_the_slot_counts(): void
    {
        $this->madeAt('2026-11-20 12:00', '2026-11-24 15:00', [
            'status' => BookingStatus::CANCELLED,
            'cancel_reason' => CancelReason::PATIENT_CANCELLED,
            'cancelled_at' => '2026-11-24 12:00:00',
        ]);
        $this->madeAt('2026-11-24 13:30', '2026-11-24 15:00');
        $this->madeAt('2026-11-20 12:00', '2026-11-25 15:00', [
            'status' => BookingStatus::CANCELLED,
            'cancel_reason' => CancelReason::PATIENT_CANCELLED,
            'cancelled_at' => '2026-11-25 12:00:00',
        ]);
        $this->madeAt('2026-11-25 14:30', '2026-11-25 15:00');

        $this->assertSame(2, $this->stats()['cards']['rebooked_slots']['refilled']);
    }

    /** Late-evening bookings belong to the day they were made on. */
    public function test_bookings_made_late_in_the_evening_stay_on_their_own_day(): void
    {
        $this->madeAt('2026-10-31 23:00', '2026-11-20 10:00'); // the day before the range
        $this->madeAt('2026-11-30 23:00', '2026-11-20 10:00'); // the range's last evening
        $this->madeAt('2026-11-30 22:30', '2026-11-20 10:00');

        $this->assertSame(2, $this->stats()['cards']['outside_hours']['sample']);
    }

    public function test_the_first_reminder_day_is_the_clinics_own_day(): void
    {
        $first = $this->booking('2026-11-15 10:00', ['status' => BookingStatus::DONE]);
        $this->reminder($first, '2026-11-14 23:30:00');

        $this->assertSame('2026-11-14', $this->stats()['cards']['no_show']['activation_date']);
    }

    /*
    | Link previews are not patients
    */

    public function test_a_link_preview_or_crawler_does_not_count_as_opening_the_link(): void
    {
        $booking = $this->booking('2026-11-30 18:00');

        $this->withHeader('User-Agent', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)')
            ->get($booking->trackingUrl())->assertOk();
        $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0 A')->get($booking->trackingUrl())->assertOk();
        $this->withHeader('User-Agent', '')->get($booking->trackingUrl())->assertOk();
        $this->call('HEAD', $booking->trackingUrl(), [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)']);

        $this->assertSame(0, BookingLinkOpen::count());

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; CUBOT X30) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36')
            ->get($booking->trackingUrl())->assertOk();

        $this->assertSame(1, BookingLinkOpen::count());
    }

    public function test_an_open_is_stamped_in_the_applications_clock(): void
    {
        $booking = $this->booking('2026-11-30 18:00');

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->get($booking->trackingUrl())->assertOk();

        $this->assertSame('2026-11-30 14:00:00', BookingLinkOpen::first()->getRawOriginal('opened_at'));
    }
}
