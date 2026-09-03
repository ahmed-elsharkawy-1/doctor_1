<?php

namespace Tests\Feature\Queue;

use App\Enums\BookingKind;
use App\Models\Booking;
use App\Services\V1\Queue\QueuePositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class QueuePositionServiceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private QueuePositionService $positions;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 3 September 2026, mid-morning in the clinic's own zone.
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->positions = app(QueuePositionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(string $time, int $duration = 20): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 '.$time, $this->clinic->timezone), $duration)
            ->create();
    }

    public function test_it_counts_the_patients_ahead_in_queue_order(): void
    {
        $this->booking('09:00');
        $this->booking('09:20');
        $mine = $this->booking('09:40');

        $position = $this->positions->for($mine);

        $this->assertSame(2, $position->ahead);
        $this->assertSame(3, $position->total);
    }

    public function test_the_first_patient_is_next(): void
    {
        $mine = $this->booking('09:00');
        $this->booking('09:20');

        $position = $this->positions->for($mine);

        $this->assertSame(0, $position->ahead);
        $this->assertTrue($position->isNext());
    }

    public function test_an_emergency_booking_raises_the_count(): void
    {
        $mine = $this->booking('09:00');

        $this->assertSame(0, $this->positions->for($mine)->ahead);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 11:00', $this->clinic->timezone))
            ->create(['booking_kind' => BookingKind::EMERGENCY]);

        // The design warns the patient that this can happen.
        $this->assertSame(1, $this->positions->for($mine->fresh())->ahead);
    }

    public function test_the_patient_with_the_doctor_is_still_ahead(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:40', $this->clinic->timezone))
            ->withDoctor()
            ->create();

        $mine = $this->booking('10:00');

        $this->assertSame(1, $this->positions->for($mine)->ahead);
    }

    public function test_finished_patients_are_not_counted(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->done()->create();
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:20', $this->clinic->timezone))->cancelled()->create();
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:30', $this->clinic->timezone))->noShow()->create();

        $mine = $this->booking('09:40');

        $position = $this->positions->for($mine);

        $this->assertSame(0, $position->ahead);
        $this->assertSame(1, $position->total);
    }

    public function test_another_clinics_queue_is_never_counted(): void
    {
        $other = $this->otherClinic();

        Booking::factory()->forClinic($other)
            ->at(Carbon::parse('2026-09-03 09:00', $other->timezone))->create();

        $mine = $this->booking('09:40');

        $this->assertSame(0, $this->positions->for($mine)->ahead);
    }

    public function test_the_totals_split_normal_and_emergency(): void
    {
        $mine = $this->booking('09:20');
        $this->booking('09:40');

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))
            ->create(['booking_kind' => BookingKind::EMERGENCY]);

        $position = $this->positions->for($mine->fresh());

        $this->assertSame(3, $position->total);
        $this->assertSame(2, $position->normal);
        $this->assertSame(1, $position->emergency);
    }

    public function test_there_is_no_position_before_the_day_of_the_visit(): void
    {
        $tomorrow = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-04 09:00', $this->clinic->timezone))
            ->create();

        $this->assertNull($this->positions->for($tomorrow));
    }

    public function test_there_is_no_position_once_the_visit_is_over(): void
    {
        $done = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))->done()->create();
        $cancelled = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:20', $this->clinic->timezone))->cancelled()->create();

        $this->assertNull($this->positions->for($done));
        $this->assertNull($this->positions->for($cancelled));
    }

    public function test_the_expected_time_adds_the_visits_still_ahead(): void
    {
        $this->booking('09:00', 30);
        $this->booking('09:30', 15);
        $mine = $this->booking('09:45');

        // 10:00 now, plus 30 and 15 minutes still waiting to be seen.
        $this->assertSame(
            '10:45',
            $this->positions->for($mine)->expectedAt->format('H:i'),
        );
    }

    public function test_the_expected_time_is_never_earlier_than_the_appointment(): void
    {
        $mine = $this->booking('18:00');

        // An empty waiting room at 10:00 does not mean a 18:00 booking is seen now.
        $this->assertSame(
            '18:00',
            $this->positions->for($mine)->expectedAt->format('H:i'),
        );
    }

    public function test_the_patient_with_the_doctor_only_delays_by_what_remains(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:50', $this->clinic->timezone), 20)
            ->withDoctor()
            ->create();

        $mine = $this->booking('10:00');

        // Called in at 09:50 for 20 minutes; at 10:00 only 10 of those are left.
        $this->assertSame(
            '10:10',
            $this->positions->for($mine)->expectedAt->format('H:i'),
        );
    }

    public function test_a_visit_running_over_never_pushes_the_estimate_backwards(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone), 20)
            ->withDoctor()
            ->create();

        $mine = $this->booking('09:30');

        // That visit was due to end at 09:20 and it is now 10:00. It cannot
        // subtract time from the estimate.
        $this->assertSame(
            '10:00',
            $this->positions->for($mine)->expectedAt->format('H:i'),
        );
    }
}
