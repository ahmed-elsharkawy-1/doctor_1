<?php

namespace Tests\Feature\SelfBooking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\ApiErrorCode;
use App\Enums\BookingSource;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\SlotHold;
use App\Models\VisitType;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\ClinicDayLock;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Booking\SlotHoldService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Slot holds — the claim somebody makes on a time while they fill in the rest
 * of the form.
 *
 * The day lock already made double-booking impossible. What a hold adds is
 * that two people never spend a minute each on a slot only one of them can
 * have, which is the part patients and secretaries actually notice.
 *
 * Holds block everyone alike: there is no staff override and no patient
 * override. One rule, one code path.
 */
class SlotHoldTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Blocking
    |--------------------------------------------------------------------------
    */

    public function test_a_held_slot_is_unavailable_to_everybody_else(): void
    {
        $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $this->assertFalse($this->isFree('09:00'));
    }

    /**
     * If a holder's own claim greyed out their slot, they could never book the
     * time they were sitting on.
     */
    public function test_a_holder_is_never_blocked_by_its_own_claim(): void
    {
        $hold = $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $this->assertTrue($this->isFree('09:00', $hold->token));
    }

    /**
     * There is no override in either direction. A secretary waits for a
     * patient's hold exactly as a patient waits for hers.
     */
    public function test_staff_and_patients_block_each_other_alike(): void
    {
        $patientHold = $this->holds()->hold(
            $this->clinic, $this->visitTypeId(), self::DAY, '09:00',
            BookingSource::PATIENT_WEB,
        );

        $this->assertFalse($this->isFree('09:00'));

        $this->holds()->release($patientHold->token);

        $this->holds()->hold(
            $this->clinic, $this->visitTypeId(), self::DAY, '09:20',
            BookingSource::CLINIC, $this->owner,
        );

        $this->assertFalse($this->isFree('09:20'));
    }

    /**
     * Expiry is a query filter, so a lapsed hold stops counting the moment it
     * lapses — whether or not anything has swept the table.
     */
    public function test_an_expired_hold_blocks_nothing(): void
    {
        $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $this->assertFalse($this->isFree('09:00'));

        Carbon::setTestNow(Carbon::now()->addMinutes(
            (int) config('clinic.self_booking.hold_ttl_minutes') + 1,
        ));

        $this->assertTrue($this->isFree('09:00'));
    }

    public function test_a_hold_cannot_be_taken_on_an_already_booked_slot(): void
    {
        $this->book('09:00');

        $this->expectException(ApiException::class);

        $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');
    }

    public function test_a_second_holder_cannot_take_a_held_slot(): void
    {
        $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $this->expectException(ApiException::class);

        $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');
    }

    /*
    |--------------------------------------------------------------------------
    | Moving and letting go
    |--------------------------------------------------------------------------
    */

    public function test_moving_a_hold_frees_the_slot_it_left(): void
    {
        $first = $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $second = $this->holds()->hold(
            $this->clinic, $this->visitTypeId(), self::DAY, '09:20',
            token: $first->token,
        );

        $this->assertSame($first->token, $second->token);
        $this->assertSame(1, SlotHold::count());
        $this->assertTrue($this->isFree('09:00'));
        $this->assertFalse($this->isFree('09:20'));
    }

    public function test_releasing_gives_the_slot_straight_back(): void
    {
        $hold = $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $this->holds()->release($hold->token);

        $this->assertTrue($this->isFree('09:00'));
        $this->assertSame(0, SlotHold::count());
    }

    public function test_releasing_an_unknown_token_is_harmless(): void
    {
        $this->holds()->release('never-existed');
        $this->holds()->release(null);

        $this->assertSame(0, SlotHold::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Redeeming a hold
    |--------------------------------------------------------------------------
    */

    public function test_the_holder_can_book_the_slot_it_is_holding(): void
    {
        $hold = $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        $booking = $this->book('09:00', $hold->token);

        $this->assertNotNull($booking->id);
        // The hold has done its job and must not outlive the booking.
        $this->assertSame(0, SlotHold::count());
    }

    /**
     * The whole point: somebody else's claim is refused the same way a booking
     * would be, with the message the screen already knows how to show.
     */
    public function test_anybody_else_is_refused_the_held_slot(): void
    {
        $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        try {
            $this->book('09:00');
            $this->fail('A held slot must not be bookable by anyone else.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::SLOT_UNAVAILABLE, $e->errorCode);
        }
    }

    public function test_a_lapsed_hold_does_not_reserve_the_slot_for_its_holder(): void
    {
        $hold = $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        Carbon::setTestNow(Carbon::now()->addMinutes(
            (int) config('clinic.self_booking.hold_ttl_minutes') + 1,
        ));

        // Somebody else takes it while the holder is away.
        $this->book('09:00');

        try {
            $this->book('09:00', $hold->token);
            $this->fail('An expired hold must not win against a real booking.');
        } catch (ApiException $e) {
            $this->assertSame(ApiErrorCode::SLOT_UNAVAILABLE, $e->errorCode);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The lock they all queue behind
    |--------------------------------------------------------------------------
    */

    /**
     * The invariant with no other safety net.
     *
     * Bookings and holds both claim time, so both have to queue behind the
     * same clinic-day lock. If either built its own copy of the key and the
     * two drifted apart, they would stop serialising against each other and
     * double-booking would quietly become possible again — with nothing
     * failing to say so.
     *
     * Holding the lock by its published key and watching a hold refuse to be
     * taken is what proves they are the same lock.
     */
    public function test_taking_a_hold_queues_behind_the_clinic_day_lock(): void
    {
        $key = app(ClinicDayLock::class)->key(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
        );

        // Both the lock's own expiry and the waiting are measured against the
        // clock, and this class freezes it. Time has to run for real here —
        // and it has to be running *before* the lock is taken, or the lock
        // would be stamped with a frozen expiry and read as already lapsed.
        // One second of waiting is enough to prove it queued.
        config(['clinic.locking.day_lock_wait_seconds' => 1]);
        Carbon::setTestNow();

        $lock = Cache::lock($key, 30);
        $this->assertTrue($lock->get(), 'The day lock should have been free.');

        try {
            $this->holds()->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');
            $this->fail('A hold must wait for the clinic-day lock.');
        } catch (LockTimeoutException) {
            $this->assertSame(0, SlotHold::count());
        } finally {
            $lock->release();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | What a patient may hold
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_cannot_hold_a_type_the_clinic_keeps_to_itself(): void
    {
        $private = VisitType::factory()->notSelfBookable()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'عملية',
            'duration_minutes' => 20,
        ]);

        $this->expectException(ApiException::class);

        $this->holds()->hold(
            $this->clinic, $private->id, self::DAY, '09:00',
            BookingSource::PATIENT_WEB,
        );
    }

    public function test_the_clinic_may_still_hold_its_own_private_type(): void
    {
        $private = VisitType::factory()->notSelfBookable()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'عملية',
            'duration_minutes' => 20,
        ]);

        $hold = $this->holds()->hold(
            $this->clinic, $private->id, self::DAY, '09:00',
            BookingSource::CLINIC, $this->owner,
        );

        $this->assertSame($private->id, $hold->visit_type_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    */

    public function test_the_purge_clears_only_what_has_lapsed(): void
    {
        SlotHold::factory()->forClinic($this->clinic)->expired()->create();
        $live = SlotHold::factory()->forClinic($this->clinic)->create();

        $this->assertSame(1, $this->holds()->purgeExpired());
        $this->assertSame([$live->id], SlotHold::pluck('id')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function holds(): SlotHoldService
    {
        return app(SlotHoldService::class);
    }

    private function visitTypeId(): int
    {
        return (int) $this->clinic->visitTypes()->active()->value('id');
    }

    private function isFree(string $time, ?string $holdToken = null): bool
    {
        $visitType = $this->clinic->visitTypes()->active()->firstOrFail();

        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
            $visitType,
            null,
            $holdToken,
        );

        foreach ($availability->slots as $slot) {
            if ($slot->startAt->format('H:i') === $time) {
                return $slot->isAvailable;
            }
        }

        $this->fail("The clinic never offers {$time}.");
    }

    private function book(string $time, ?string $holdToken = null): Booking
    {
        return app(BookingService::class)->create(
            $this->clinic,
            new BookingData(
                patientId: null,
                patientName: 'سارة أحمد',
                phone: '0101222'.random_int(1000, 9999),
                age: 30,
                whatsappOptIn: true,
                visitTypeId: $this->visitTypeId(),
                date: self::DAY,
                startTime: $time,
                holdToken: $holdToken,
            ),
            $this->owner,
        );
    }
}
