<?php

namespace Tests\Feature\SelfBooking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Queue\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Where a booking came from, and what the clinic does about it.
 *
 * `source` is a second dimension beside the status, like `booking_kind` — not
 * a seventh BookingStatus. These tests pin that down: a self-booking is
 * `booked` like any other and behaves identically everywhere the lifecycle
 * cares about. The only thing that differs is the label it carries.
 */
class BookingSourceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse('2026-09-03')));
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
    | Writing the source
    |--------------------------------------------------------------------------
    */

    public function test_a_booking_the_clinic_makes_is_sourced_to_the_clinic(): void
    {
        $booking = $this->create();

        $this->assertSame(BookingSource::CLINIC, $booking->source);
        $this->assertSame($this->owner->id, $booking->created_by);
    }

    /**
     * There is no account behind a public page. `created_by` has always been
     * nullable; this is the first caller that actually leaves it empty.
     */
    public function test_a_patient_booking_has_a_source_and_no_creator(): void
    {
        $booking = $this->create(
            source: BookingSource::PATIENT_WEB,
            actor: null,
        );

        $this->assertSame(BookingSource::PATIENT_WEB, $booking->source);
        $this->assertNull($booking->created_by);
    }

    /**
     * A self-booking is a normal booking. If this ever stops being true, the
     * decision to keep `source` out of BookingStatus has been undone
     * somewhere.
     */
    public function test_a_patient_booking_is_booked_and_holds_its_slot(): void
    {
        $booking = $this->create(source: BookingSource::PATIENT_WEB, actor: null);

        $this->assertSame(BookingStatus::BOOKED, $booking->status);

        $held = $this->clinic->bookings()->occupyingSlot()->pluck('id');

        $this->assertTrue($held->contains($booking->id));
    }

    /**
     * The forgery guard. `source` is the one field a client must never set:
     * accepting it would let any API caller label their own booking as a
     * patient's, which is the whole claim the phone verification establishes.
     */
    public function test_a_request_body_can_never_set_the_source(): void
    {
        $data = BookingData::fromArray([
            'patient_name' => 'سارة أحمد',
            'phone' => '01012225521',
            'visit_type_id' => $this->visitTypeId(),
            'date' => '2026-09-03',
            'start_time' => '09:00',
            // Exactly what a forged request would send.
            'source' => BookingSource::PATIENT_WEB->value,
        ]);

        $this->assertSame(BookingSource::CLINIC, $data->source);

        $booking = app(BookingService::class)->create($this->clinic, $data, $this->owner);

        $this->assertSame(BookingSource::CLINIC, $booking->source);
    }

    /*
    |--------------------------------------------------------------------------
    | Telling the two apart
    |--------------------------------------------------------------------------
    */

    /**
     * The badge on the queue card, and the filter behind "show me only what
     * patients booked themselves".
     *
     * There is deliberately nothing to acknowledge. A self-booking is real the
     * moment it is made, so `source` is a permanent fact about the booking
     * rather than a state anybody clears — it still reads the same after the
     * visit is done or cancelled.
     */
    public function test_a_self_booking_is_distinguishable_for_as_long_as_it_exists(): void
    {
        $theirs = Booking::factory()->forClinic($this->clinic)->selfBooked()->create();
        $ours = Booking::factory()->forClinic($this->clinic)->create();

        $this->assertTrue($theirs->isSelfBooked());
        $this->assertFalse($ours->isSelfBooked());

        $this->assertSame(
            [$theirs->id],
            $this->clinic->bookings()->selfBooked()->pluck('id')->all(),
        );

        app(BookingStatusService::class)->cancel($theirs, CancelReason::cases()[0]);

        $this->assertTrue($theirs->fresh()->isSelfBooked());
        $this->assertSame(
            [$theirs->id],
            $this->clinic->bookings()->selfBooked()->pluck('id')->all(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function create(
        BookingSource $source = BookingSource::CLINIC,
        mixed $actor = false,
        string $startTime = '09:00',
    ): Booking {
        return app(BookingService::class)->create(
            $this->clinic,
            new BookingData(
                patientId: null,
                patientName: 'سارة أحمد',
                phone: '0101222'.random_int(1000, 9999),
                age: 30,
                whatsappOptIn: true,
                visitTypeId: $this->visitTypeId(),
                date: '2026-09-03',
                startTime: $startTime,
                source: $source,
            ),
            $actor === false ? $this->owner : $actor,
        );
    }

    private function visitTypeId(): int
    {
        return (int) $this->clinic->visitTypes()->active()->value('id');
    }
}
