<?php

namespace Tests\Feature\Queue;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Models\Booking;
use App\Services\V1\Queue\BookingStatusService;
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

    /**
     * Being first is not being called in. The first booking of the day is
     * first from midnight onwards, and a patient still at home must not be
     * told to walk into the examination room.
     */
    public function test_the_first_patient_is_not_next_until_they_arrive(): void
    {
        $mine = $this->booking('09:00');
        $this->booking('09:20');

        $position = $this->positions->for($mine);

        $this->assertSame(0, $position->ahead);
        $this->assertFalse($position->isNext());
        $this->assertTrue($position->isFirstInLine());
    }

    public function test_the_first_patient_is_next_once_they_are_in_the_clinic(): void
    {
        $mine = $this->booking('09:00');
        $this->booking('09:20');

        $mine->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now()]);

        $position = $this->positions->for($mine->fresh());

        $this->assertTrue($position->isNext());
        $this->assertFalse($position->isFirstInLine());
    }

    public function test_a_patient_with_the_doctor_is_still_their_turn(): void
    {
        $mine = $this->booking('09:00');
        $mine->update(['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);

        $this->assertTrue($this->positions->for($mine->fresh())->isNext());
    }

    /**
     * Arrival alone is not enough either — an earlier appointment is still in
     * front, and it would be whether it had arrived or not.
     */
    public function test_an_arrived_patient_behind_another_arrival_is_not_next(): void
    {
        $first = $this->booking('09:00');
        $first->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now()]);

        $mine = $this->booking('09:20');
        $mine->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now()]);

        $position = $this->positions->for($mine->fresh());

        $this->assertFalse($position->isNext());
        $this->assertFalse($position->isFirstInLine());
    }

    public function test_an_emergency_arriving_raises_the_count(): void
    {
        $mine = $this->booking('09:00');

        $this->assertSame(0, $this->positions->for($mine)->ahead);

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 11:00', $this->clinic->timezone))
            ->arrived()
            ->create(['booking_kind' => BookingKind::EMERGENCY]);

        // The design warns the patient that this can happen.
        $this->assertSame(1, $this->positions->for($mine->fresh())->ahead);
    }

    /**
     * Going in to the doctor is where a patient leaves the count; the time
     * left on that visit still shows in the estimate.
     */
    public function test_the_patient_with_the_doctor_is_no_longer_counted(): void
    {
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:40', $this->clinic->timezone))
            ->withDoctor()
            ->create();

        $mine = $this->booking('10:00');
        $this->booking('10:20');

        $this->assertSame(0, $this->positions->for($mine)->ahead);
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
            ->arrived()
            ->create(['booking_kind' => BookingKind::EMERGENCY]);

        $position = $this->positions->for($mine->fresh());

        $this->assertSame(3, $position->total);
        $this->assertSame(2, $position->normal);
        $this->assertSame(1, $position->emergency);
    }

    /**
     * The patient's own wait, split: only those ranked ahead count, never the
     * patients who come after them or the clinic's whole day.
     */
    public function test_the_split_counts_only_the_patients_ahead(): void
    {
        $this->booking('09:00');                 // normal, ahead
        $mine = $this->booking('09:20');
        $this->booking('09:40');                 // normal, after
        $this->booking('10:00');                 // normal, after
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:30', $this->clinic->timezone))
            ->arrived()
            ->create(['booking_kind' => BookingKind::EMERGENCY]); // in the clinic: always ahead

        $position = $this->positions->for($mine->fresh());

        $this->assertSame(2, $position->ahead);
        $this->assertSame(1, $position->aheadNormal);
        $this->assertSame(1, $position->aheadEmergency);
        $this->assertSame($position->ahead, $position->aheadNormal + $position->aheadEmergency);
    }

    /*
    |--------------------------------------------------------------------------
    | The count is by appointment, not by who turned up first
    |--------------------------------------------------------------------------
    |
    | Ahead of a patient: normal bookings with an earlier appointment that
    | have not gone in to the doctor, arrived or not, and emergencies once
    | they are in the clinic. Only a cancellation, a no-show, a new booking
    | in an earlier slot, someone going in, or an emergency arriving moves it.
    */

    private function ahead(Booking $booking): int
    {
        return $this->positions->for($booking->fresh())->ahead;
    }

    private function emergency(?string $arrivedAt = null): Booking
    {
        return Booking::factory()->forClinic($this->clinic)->create([
            'booking_kind' => BookingKind::EMERGENCY,
            'start_at' => null,
            'end_at' => null,
            'status' => $arrivedAt === null ? BookingStatus::BOOKED : BookingStatus::ARRIVED,
            'arrived_at' => $arrivedAt === null ? null : Carbon::parse('2026-09-03 '.$arrivedAt, $this->clinic->timezone),
            'queue_entered_at' => $arrivedAt === null ? null : Carbon::parse('2026-09-03 '.$arrivedAt, $this->clinic->timezone),
        ]);
    }

    /**
     * The complaint: patients with later appointments turning up early
     * pushed the number up for a patient still on their way.
     */
    public function test_later_appointments_arriving_first_never_raise_the_count(): void
    {
        $this->booking('09:00');
        $mine = $this->booking('10:00');
        $later = [$this->booking('10:20'), $this->booking('10:40'), $this->booking('11:00')];

        $this->assertSame(1, $this->ahead($mine));

        foreach ($later as $patient) {
            $patient->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);
            $this->assertSame(1, $this->ahead($mine));
        }
    }

    /**
     * An earlier appointment goes in first even when it turns up late, so it
     * was ahead all along and arriving changes nothing — before or after the
     * patient's own arrival.
     */
    public function test_an_earlier_appointment_stays_ahead_however_late_it_arrives(): void
    {
        $early = $this->booking('09:30');
        $mine = $this->booking('10:00');

        $mine->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);
        $this->assertSame(1, $this->ahead($mine));

        Carbon::setTestNow(Carbon::parse('2026-09-03 10:15:00', 'Africa/Cairo'));
        $early->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);

        $this->assertSame(1, $this->ahead($mine));
    }

    public function test_a_cancellation_ahead_lowers_it_and_rebooking_the_slot_raises_it_again(): void
    {
        $this->booking('09:00');
        $freed = $this->booking('09:20');
        $mine = $this->booking('09:40');

        $this->assertSame(2, $this->ahead($mine));

        $freed->update(['status' => BookingStatus::CANCELLED, 'cancelled_at' => now()]);
        $this->assertSame(1, $this->ahead($mine));

        $this->booking('09:20');
        $this->assertSame(2, $this->ahead($mine));

        // A slot after theirs is nobody they wait for.
        $this->booking('10:20');
        $this->assertSame(2, $this->ahead($mine));
    }

    /**
     * A no-show counts the moment the secretary marks it — not when the
     * appointment time has passed with nobody there.
     */
    public function test_a_no_show_ahead_lowers_it_only_once_it_is_marked(): void
    {
        $absent = $this->booking('09:00');
        $mine = $this->booking('09:40');

        Carbon::setTestNow(Carbon::parse('2026-09-03 11:00:00', 'Africa/Cairo'));
        $this->assertSame(1, $this->ahead($mine));

        $absent->update(['status' => BookingStatus::NO_SHOW, 'cancelled_at' => now()]);
        $this->assertSame(0, $this->ahead($mine));
    }

    /**
     * Going in to the doctor is when a patient stops being ahead; finishing
     * afterwards takes nothing more off. A secretary who skips "with the
     * doctor" and marks the visit done takes them off just the same.
     */
    public function test_going_in_lowers_it_once_and_finishing_does_not_lower_it_again(): void
    {
        $first = $this->booking('09:00');
        $second = $this->booking('09:20');
        $mine = $this->booking('09:40');

        $first->update(['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);
        $this->assertSame(1, $this->ahead($mine));

        $first->update(['status' => BookingStatus::DONE, 'completed_at' => now()]);
        $this->assertSame(1, $this->ahead($mine));

        $second->update(['status' => BookingStatus::DONE, 'completed_at' => now()]);
        $this->assertSame(0, $this->ahead($mine));
    }

    public function test_an_emergency_counts_from_its_arrival_until_it_goes_in(): void
    {
        $mine = $this->booking('09:40');
        $emergency = $this->emergency();

        // Added by the secretary, but not in the clinic yet.
        $this->assertSame(0, $this->ahead($mine));

        $emergency->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);
        $position = $this->positions->for($mine->fresh());
        $this->assertSame(1, $position->ahead);
        $this->assertSame(0, $position->aheadNormal);
        $this->assertSame(1, $position->aheadEmergency);

        $emergency->update(['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);
        $this->assertSame(0, $this->ahead($mine));
    }

    /**
     * Two bookings on one time (a double booking) stay in the order they
     * were made, so neither number can flip.
     */
    public function test_a_shared_time_keeps_the_order_the_bookings_were_made_in(): void
    {
        $first = $this->booking('09:00');
        $second = $this->booking('09:00');

        $this->assertSame(0, $this->ahead($first));
        $this->assertSame(1, $this->ahead($second));

        $second->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);
        $this->assertSame(0, $this->ahead($first));
        $this->assertSame(1, $this->ahead($second));
    }

    /**
     * An emergency has no appointment: only emergencies already in the
     * clinic before it are ahead, never the normal bookings.
     */
    public function test_an_emergency_waits_only_for_emergencies_that_arrived_before_it(): void
    {
        $this->booking('09:00');
        $this->emergency(arrivedAt: '09:50');
        $this->emergency();                       // added, not here yet
        $mine = $this->emergency(arrivedAt: '09:55');
        $this->emergency(arrivedAt: '09:58');

        $position = $this->positions->for($mine->fresh());

        $this->assertSame(1, $position->ahead);
        $this->assertSame(1, $position->aheadEmergency);
        $this->assertSame(0, $position->aheadNormal);
    }

    public function test_an_emergency_not_yet_here_waits_for_every_emergency_that_is(): void
    {
        $this->emergency(arrivedAt: '09:50');
        $this->emergency(arrivedAt: '09:55');
        $mine = $this->emergency();

        $this->assertSame(2, $this->ahead($mine));
    }

    /**
     * Nobody left ahead, but the doctor is still with someone: the patient
     * is next, not called in.
     */
    public function test_nobody_ahead_while_the_doctor_is_busy_is_next_not_called_in(): void
    {
        $inside = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:20', $this->clinic->timezone))->withDoctor()->create();

        $mine = $this->booking('09:40');
        $mine->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);

        $position = $this->positions->for($mine->fresh());
        $this->assertSame(0, $position->ahead);
        $this->assertTrue($position->isUpNext());
        $this->assertFalse($position->isNext());
        $this->assertFalse($position->isFirstInLine());

        $inside->update(['status' => BookingStatus::DONE, 'completed_at' => now()]);

        $position = $this->positions->for($mine->fresh());
        $this->assertTrue($position->isNext());
        $this->assertFalse($position->isUpNext());
    }

    /**
     * A later patient called in out of turn was never ahead, so the count
     * stays — but the room is still taken.
     */
    public function test_a_later_patient_called_in_early_leaves_the_count_alone(): void
    {
        $this->booking('09:00');
        $mine = $this->booking('09:40');
        $later = $this->booking('10:20');

        $later->update(['status' => BookingStatus::WITH_DOCTOR, 'arrived_at' => now(), 'called_in_at' => now()]);

        $this->assertSame(1, $this->ahead($mine));
    }

    /**
     * A whole evening, arrivals in a jumbled order: between the events that
     * should move it, the number never goes up.
     */
    public function test_a_jumbled_evening_never_raises_the_count(): void
    {
        $times = ['09:00', '09:20', '09:40', '10:00', '10:20', '10:40', '11:00', '11:20'];
        $patients = collect($times)->mapWithKeys(fn ($time) => [$time => $this->booking($time)]);
        $mine = $patients['10:40'];

        $seen = [$this->ahead($mine)];
        $arrive = fn ($time) => $patients[$time]->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);

        foreach (['11:20', '10:20', '11:00', '09:20', '10:40', '09:00', '10:00'] as $time) {
            $arrive($time);
            $seen[] = $this->ahead($mine);
        }
        foreach (['09:00', '09:20', '10:00', '10:20'] as $time) {
            $patients[$time]->update(['status' => BookingStatus::WITH_DOCTOR, 'called_in_at' => now()]);
            $seen[] = $this->ahead($mine);
            $patients[$time]->update(['status' => BookingStatus::DONE, 'completed_at' => now()]);
            $seen[] = $this->ahead($mine);
        }

        $sorted = $seen;
        rsort($sorted);
        $this->assertSame($sorted, $seen, 'never goes up: '.implode(' ', $seen));
        $this->assertSame(5, $seen[0]);
        $this->assertSame(1, end($seen));
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

    /**
     * The complaint: ten booked ahead and on time, then the count reads
     * fifteen. Evening patients who turn up early are ranked first on the
     * clinic's screen, but they were never in front of this patient.
     */
    public function test_patients_booked_later_who_arrive_early_are_not_counted(): void
    {
        $this->booking('10:20');
        $this->booking('10:40');
        $mine = $this->booking('11:00');

        foreach (['12:00', '12:20', '12:40'] as $time) {
            $this->booking($time)->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);
        }

        $position = $this->positions->for($mine->fresh());

        $this->assertSame(2, $position->ahead);
        $this->assertSame(2, $position->aheadNormal);
        // Their visits are not waited on either.
        $this->assertSame('11:00', $position->expectedAt->format('H:i'));
    }

    public function test_an_arrival_booked_later_never_makes_an_arrived_patient_wait(): void
    {
        $early = $this->booking('12:00');
        $early->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now(), 'queue_entered_at' => now()]);

        $mine = $this->booking('10:20');
        $mine->update(['status' => BookingStatus::ARRIVED, 'arrived_at' => now()->addMinutes(5), 'queue_entered_at' => now()->addMinutes(5)]);

        $this->assertTrue($this->positions->for($mine->fresh())->isNext());
    }

    /**
     * Whoever is in the room is what is being waited on, whenever they were
     * booked for — a clinic running ahead of itself calls a later patient in.
     */
    public function test_a_patient_booked_later_but_with_the_doctor_still_counts(): void
    {
        $mine = $this->booking('10:20');

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 12:00', $this->clinic->timezone))
            ->withDoctor()
            ->create();

        $position = $this->positions->for($mine->fresh());

        $this->assertSame(0, $position->ahead);
        $this->assertTrue($position->doctorBusy);
    }

    /**
     * Every status change, a cancellation and the booking that replaces it:
     * none of it may count anybody twice or push the number up.
     */
    public function test_status_changes_and_a_replaced_booking_never_raise_the_count(): void
    {
        $statuses = app(BookingStatusService::class);

        $ahead = collect(['10:00', '10:20', '10:40'])->map(fn (string $time): Booking => $this->booking($time));
        $mine = $this->booking('11:00');
        $this->booking('11:20');

        $count = fn (): int => $this->positions->for($mine->fresh())->ahead;

        $this->assertSame(3, $count());

        $statuses->cancel($ahead[1], CancelReason::selectable()[0]);
        $this->assertSame(2, $count());

        $this->booking('10:20');
        $this->assertSame(3, $count());

        $statuses->arrive($ahead[0]->fresh());
        $this->assertSame(3, $count());

        // In the room now: no longer waiting before them.
        $statuses->callIn($ahead[0]->fresh());
        $this->assertSame(2, $count());

        $statuses->complete($ahead[0]->fresh());
        $this->assertSame(2, $count());
    }
}
