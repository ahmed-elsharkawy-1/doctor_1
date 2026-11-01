<?php

namespace Tests\Feature\Patient;

use App\Enums\BookingStatus;
use App\Enums\OnlineBookingBlockReason;
use App\Models\Booking;
use App\Models\Patient;
use App\Services\V1\Patients\OnlineBookingService;
use App\Services\V1\Queue\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The rule that keeps a patient who keeps not turning up off the public
 * booking page: two no-shows inside the last 30 days, at a clinic that has it
 * switched on. The clinic can lift it, or impose it by hand.
 */
class OnlineBookingBlockTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['no_show_block_enabled' => true]);

        $this->patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => '+201012225521',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $date, ?Patient $patient = null): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => ($patient ?? $this->patient)->id]);
    }

    /** Marks a booking no-show the way the secretary does. */
    private function noShow(string $date, ?Patient $patient = null): Booking
    {
        return app(BookingStatusService::class)->update($this->booking($date, $patient), BookingStatus::NO_SHOW);
    }

    private function service(): OnlineBookingService
    {
        return app(OnlineBookingService::class);
    }

    public function test_a_second_no_show_inside_thirty_days_blocks_the_patient(): void
    {
        $this->noShow('2026-11-02');
        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));

        $this->noShow('2026-11-09');

        $patient = $this->patient->fresh();
        $this->assertTrue($this->service()->isBlocked($patient));
        $this->assertSame(OnlineBookingBlockReason::NO_SHOWS, $patient->online_booking_block_reason);
        $this->assertNotNull($patient->online_booking_blocked_at);
    }

    public function test_no_shows_further_apart_than_thirty_days_do_not_block(): void
    {
        $this->noShow('2026-10-10');
        $this->noShow('2026-11-09');

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }

    /** The last 30 days, not the calendar month: the 1st does not wipe the slate. */
    public function test_the_window_runs_across_the_end_of_a_month(): void
    {
        $this->noShow('2026-10-30');
        $this->noShow('2026-11-01');

        $this->assertTrue($this->service()->isBlocked($this->patient->fresh()));
    }

    /** It is the patient's habit that counts, whoever made the booking. */
    public function test_no_shows_on_bookings_the_clinic_made_count_too(): void
    {
        Booking::factory()->forClinic($this->clinic)->noShow()
            ->at(Carbon::parse('2026-11-03 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->noShow('2026-11-09');

        $this->assertTrue($this->service()->isBlocked($this->patient->fresh()));
    }

    public function test_cancellations_do_not_count(): void
    {
        Booking::factory()->forClinic($this->clinic)->cancelled()
            ->at(Carbon::parse('2026-11-03 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $this->patient->id]);

        $this->noShow('2026-11-09');

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }

    public function test_another_patients_no_shows_do_not_count(): void
    {
        $other = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->noShow('2026-11-03', $other);
        $this->noShow('2026-11-09');

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }

    public function test_nothing_is_blocked_at_a_clinic_with_the_rule_switched_off(): void
    {
        $this->clinic->update(['no_show_block_enabled' => false]);

        $this->noShow('2026-11-02');
        $this->noShow('2026-11-09');

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }

    /** The other way a no-show is written: the clinic day closing on its own. */
    public function test_the_day_closing_on_its_own_blocks_too(): void
    {
        $this->noShow('2026-11-02');
        $untouched = $this->booking('2026-11-09');

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertSame(BookingStatus::NO_SHOW, $untouched->fresh()->status);
        $this->assertTrue($this->service()->isBlocked($this->patient->fresh()));
    }

    public function test_the_day_closing_leaves_a_patient_with_one_no_show_alone(): void
    {
        $this->booking('2026-11-09');

        $this->artisan('clinic:close-day')->assertSuccessful();

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | The clinic's hand
    |--------------------------------------------------------------------------
    */

    public function test_the_clinic_can_lift_a_block(): void
    {
        $this->noShow('2026-11-02');
        $this->noShow('2026-11-09');

        $this->service()->allow($this->patient->fresh());

        $patient = $this->patient->fresh();
        $this->assertFalse($this->service()->isBlocked($patient));
        $this->assertNull($patient->online_booking_block_reason);
        $this->assertNotNull($patient->online_booking_allowed_at);
    }

    /** A clean start: the no-shows before the clinic lifted it are not held against them. */
    public function test_after_a_lift_only_new_no_shows_count(): void
    {
        $this->noShow('2026-11-02');
        $this->noShow('2026-11-05');
        $this->service()->allow($this->patient->fresh());

        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00', 'Africa/Cairo'));
        $this->noShow('2026-11-11');

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
        $this->assertSame(1, $this->service()->countedNoShows($this->patient->fresh()));

        Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00', 'Africa/Cairo'));
        $this->noShow('2026-11-12');

        $this->assertTrue($this->service()->isBlocked($this->patient->fresh()));
    }

    public function test_the_clinic_can_block_by_hand_even_with_the_rule_off(): void
    {
        $this->clinic->update(['no_show_block_enabled' => false]);

        $this->service()->block($this->patient);

        $patient = $this->patient->fresh();
        $this->assertTrue($this->service()->isBlocked($patient));
        $this->assertSame(OnlineBookingBlockReason::MANUAL, $patient->online_booking_block_reason);
    }

    /** Blocking a patient the rule already blocked keeps when and why it happened. */
    public function test_blocking_by_hand_does_not_rewrite_an_existing_block(): void
    {
        $this->noShow('2026-11-02');
        $this->noShow('2026-11-09');
        $blockedAt = $this->patient->fresh()->online_booking_blocked_at;

        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Africa/Cairo'));
        $this->service()->block($this->patient->fresh());

        $patient = $this->patient->fresh();
        $this->assertSame(OnlineBookingBlockReason::NO_SHOWS, $patient->online_booking_block_reason);
        $this->assertTrue($blockedAt->equalTo($patient->online_booking_blocked_at));
    }

    /** A no-show on a patient already blocked by hand keeps the clinic's own reason. */
    public function test_a_hand_block_keeps_its_reason(): void
    {
        $this->service()->block($this->patient);

        $this->noShow('2026-11-02');
        $this->noShow('2026-11-09');

        $this->assertSame(OnlineBookingBlockReason::MANUAL, $this->patient->fresh()->online_booking_block_reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Launch: applying the rule to what already happened
    |--------------------------------------------------------------------------
    */

    public function test_the_launch_command_blocks_patients_who_already_qualify(): void
    {
        foreach (['2026-11-02', '2026-11-08'] as $date) {
            Booking::factory()->forClinic($this->clinic)->noShow()
                ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
                ->create(['patient_id' => $this->patient->id]);
        }

        $once = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
        Booking::factory()->forClinic($this->clinic)->noShow()
            ->at(Carbon::parse('2026-11-08 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $once->id]);

        $this->artisan('clinic:apply-no-show-blocks')->assertSuccessful();

        $this->assertTrue($this->service()->isBlocked($this->patient->fresh()));
        $this->assertFalse($this->service()->isBlocked($once->fresh()));
    }

    public function test_the_launch_command_can_only_report(): void
    {
        foreach (['2026-11-02', '2026-11-08'] as $date) {
            Booking::factory()->forClinic($this->clinic)->noShow()
                ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
                ->create(['patient_id' => $this->patient->id]);
        }

        $this->artisan('clinic:apply-no-show-blocks', ['--dry-run' => true])
            ->expectsOutputToContain($this->patient->code)
            ->assertSuccessful();

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }

    public function test_the_launch_command_skips_clinics_with_the_rule_off(): void
    {
        $this->clinic->update(['no_show_block_enabled' => false]);

        foreach (['2026-11-02', '2026-11-08'] as $date) {
            Booking::factory()->forClinic($this->clinic)->noShow()
                ->at(Carbon::parse($date.' 09:00', 'Africa/Cairo'))
                ->create(['patient_id' => $this->patient->id]);
        }

        $this->artisan('clinic:apply-no-show-blocks')->assertSuccessful();

        $this->assertFalse($this->service()->isBlocked($this->patient->fresh()));
    }
}
