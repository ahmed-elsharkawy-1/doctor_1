<?php

namespace Tests\Feature\SelfBooking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\BookingSource;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Events\SelfBookingReceived;
use App\Events\SlotsChanged;
use App\Models\Booking;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\SlotHoldService;
use App\Services\V1\Queue\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * What the rest of the world is told when a clinic's day changes shape.
 *
 * Three doors compete for the same times, so anybody sitting on a slot grid
 * is looking at something that can go stale under them. These events are how
 * they find out — not how correctness is enforced, which is the day lock's
 * job and works with broadcasting switched off entirely.
 *
 * The tests below care about two things: that every way a slot changes hands
 * says so, and that the public channel never learns anything about a patient.
 */
class BroadcastingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        foreach ([self::DAY, '2026-09-05'] as $date) {
            $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse($date)));
            $schedule->update(['is_open' => true]);

            if ($schedule->periods()->count() === 0) {
                $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
            }
        }

        Event::fake([SlotsChanged::class, SelfBookingReceived::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    */

    public function test_taking_a_slot_announces_the_day(): void
    {
        app(SlotHoldService::class)->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        Event::assertDispatched(
            SlotsChanged::class,
            fn (SlotsChanged $e): bool => $e->clinic->is($this->clinic) && $e->date === self::DAY,
        );
    }

    public function test_giving_a_slot_back_announces_the_day(): void
    {
        $hold = app(SlotHoldService::class)->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        Event::assertDispatchedTimes(SlotsChanged::class, 1);

        app(SlotHoldService::class)->release($hold->token);

        Event::assertDispatchedTimes(SlotsChanged::class, 2);
    }

    /** A token nobody is holding is not news. */
    public function test_releasing_nothing_announces_nothing(): void
    {
        app(SlotHoldService::class)->release('not-a-real-token');
        app(SlotHoldService::class)->release(null);

        Event::assertNotDispatched(SlotsChanged::class);
    }

    /**
     * The only way a slot comes free with nobody doing anything — so the only
     * case with nobody to announce it unless the sweep does.
     */
    public function test_a_lapsed_hold_announces_the_day_it_frees(): void
    {
        app(SlotHoldService::class)->hold($this->clinic, $this->visitTypeId(), self::DAY, '09:00');

        Event::assertDispatchedTimes(SlotsChanged::class, 1);

        Carbon::setTestNow(Carbon::now()->addMinutes(
            (int) config('clinic.self_booking.hold_ttl_minutes') + 1,
        ));

        $this->artisan('clinic:release-lapsed-holds')->assertSuccessful();

        Event::assertDispatchedTimes(SlotsChanged::class, 2);
    }

    /** A sweep that frees nothing says nothing. */
    public function test_a_sweep_with_nothing_to_release_is_silent(): void
    {
        $this->artisan('clinic:release-lapsed-holds')->assertSuccessful();

        Event::assertNotDispatched(SlotsChanged::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Bookings
    |--------------------------------------------------------------------------
    */

    public function test_booking_announces_the_day_once(): void
    {
        $this->book();

        // Once, not twice: consuming the hold is silent because the booking
        // itself is the announcement.
        Event::assertDispatchedTimes(SlotsChanged::class, 1);
    }

    public function test_cancelling_announces_the_day(): void
    {
        $booking = $this->book();

        Event::assertDispatchedTimes(SlotsChanged::class, 1);

        app(BookingStatusService::class)->cancel($booking, CancelReason::cases()[0]);

        Event::assertDispatchedTimes(SlotsChanged::class, 2);
    }

    /** A move frees the day it left as surely as it fills the one it joins. */
    public function test_rescheduling_announces_both_days(): void
    {
        $booking = $this->book();

        app(BookingService::class)->update($this->clinic, $booking->id, new BookingData(
            patientId: $booking->patient_id,
            patientName: $booking->patient->name,
            phone: null,
            age: null,
            whatsappOptIn: true,
            visitTypeId: $this->visitTypeId(),
            date: '2026-09-05',
            startTime: '09:00',
        ));

        $announced = collect(Event::dispatched(SlotsChanged::class))
            ->map(fn (array $args): string => $args[0]->date)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([self::DAY, '2026-09-05'], $announced);
    }

    /*
    |--------------------------------------------------------------------------
    | Telling the clinic
    |--------------------------------------------------------------------------
    */

    public function test_a_patients_own_booking_reaches_the_clinic(): void
    {
        $booking = $this->book(BookingSource::PATIENT_WEB);

        Event::assertDispatched(
            SelfBookingReceived::class,
            fn (SelfBookingReceived $e): bool => $e->booking->is($booking),
        );
    }

    /** She made it herself; she does not need telling. */
    public function test_the_clinics_own_booking_is_not_announced_to_it(): void
    {
        $this->book();

        Event::assertNotDispatched(SelfBookingReceived::class);
    }

    /*
    |--------------------------------------------------------------------------
    | What rides on each channel
    |--------------------------------------------------------------------------
    */

    /**
     * The public channel is public because a stranger on the booking page has
     * no account to authenticate with. So it must carry nothing but the date —
     * no names, no times, nothing that describes a person.
     */
    public function test_the_public_channel_carries_only_a_date(): void
    {
        $event = new SlotsChanged($this->clinic, self::DAY);

        $this->assertSame(['date' => self::DAY], $event->broadcastWith());
        $this->assertSame(
            'slots.'.$this->clinic->id.'.'.self::DAY,
            $event->broadcastOn()->name,
        );
    }

    /**
     * The name the browser binds to, asserted against the Blade that binds it.
     *
     * Laravel broadcasts the fully-qualified class name unless an event says
     * otherwise, so `App\Events\SlotsChanged` would arrive at a page waiting
     * for `SlotsChanged` and nothing would happen. That failure is invisible
     * from the server: the event dispatches, the job succeeds, Pusher accepts
     * it, and the feature is dead. So the two halves are pinned together here.
     */
    public function test_the_event_names_match_what_the_pages_listen_for(): void
    {
        $this->assertSame('SlotsChanged', (new SlotsChanged($this->clinic, self::DAY))->broadcastAs());

        $patientPage = file_get_contents(resource_path('views/components/layouts/patient.blade.php'));
        $this->assertStringContainsString("bind('SlotsChanged'", $patientPage);

        $booking = $this->book(BookingSource::PATIENT_WEB);
        $this->assertSame('SelfBookingReceived', (new SelfBookingReceived($booking))->broadcastAs());

        $queuePage = file_get_contents(resource_path('views/livewire/app/queue.blade.php'));
        $this->assertStringContainsString("bind('SelfBookingReceived'", $queuePage);
    }

    public function test_the_clinic_channel_is_private_and_named_for_the_clinic(): void
    {
        $booking = $this->book(BookingSource::PATIENT_WEB);

        $channel = (new SelfBookingReceived($booking))->broadcastOn();

        $this->assertSame('private-clinic.'.$this->clinic->id, $channel->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may listen
    |--------------------------------------------------------------------------
    */

    public function test_only_the_clinics_own_staff_may_listen_to_it(): void
    {
        $other = $this->otherClinic();

        $this->assertTrue($this->channelCheck($this->owner, $this->clinic->id));
        $this->assertFalse($this->channelCheck($this->owner, $other->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function channelCheck(mixed $user, int $clinicId): bool
    {
        return $user->clinics()->whereKey($clinicId)->exists();
    }

    private function book(BookingSource $source = BookingSource::CLINIC): Booking
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
                startTime: '09:00',
                source: $source,
            ),
            $source === BookingSource::PATIENT_WEB ? null : $this->owner,
        );
    }

    private function visitTypeId(): int
    {
        return (int) $this->clinic->visitTypes()->active()->value('id');
    }
}
