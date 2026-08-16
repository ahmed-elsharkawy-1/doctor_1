<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingLifecycleTest extends TestCase
{
    public function test_the_status_chain_only_moves_forward(): void
    {
        $this->assertSame(BookingStatus::ARRIVED, BookingStatus::BOOKED->next());
        $this->assertSame(BookingStatus::WITH_DOCTOR, BookingStatus::ARRIVED->next());
        $this->assertSame(BookingStatus::DONE, BookingStatus::WITH_DOCTOR->next());
        $this->assertNull(BookingStatus::DONE->next());
        $this->assertNull(BookingStatus::CANCELLED->next());
        $this->assertNull(BookingStatus::NO_SHOW->next());
    }

    public function test_skipping_a_step_is_not_a_valid_transition(): void
    {
        $this->assertTrue(BookingStatus::BOOKED->canAdvanceTo(BookingStatus::ARRIVED));
        $this->assertTrue(BookingStatus::BOOKED->canAdvanceTo(BookingStatus::NO_SHOW));
        $this->assertTrue(BookingStatus::ARRIVED->canAdvanceTo(BookingStatus::NO_SHOW));
        $this->assertFalse(BookingStatus::BOOKED->canAdvanceTo(BookingStatus::WITH_DOCTOR));
        $this->assertFalse(BookingStatus::BOOKED->canAdvanceTo(BookingStatus::DONE));
    }

    public function test_a_patient_with_the_doctor_can_still_be_cancelled(): void
    {
        $this->assertTrue(BookingStatus::BOOKED->canBeCancelled());
        $this->assertTrue(BookingStatus::ARRIVED->canBeCancelled());
        $this->assertTrue(BookingStatus::WITH_DOCTOR->canBeCancelled());
        $this->assertFalse(BookingStatus::DONE->canBeCancelled());
        $this->assertFalse(BookingStatus::NO_SHOW->canBeCancelled());
    }

    public function test_cancelled_bookings_do_not_hold_a_slot(): void
    {
        $occupying = BookingStatus::occupyingSlot();

        $this->assertContains(BookingStatus::BOOKED, $occupying);
        $this->assertContains(BookingStatus::DONE, $occupying);
        $this->assertNotContains(BookingStatus::CANCELLED, $occupying);
        $this->assertNotContains(BookingStatus::NO_SHOW, $occupying);
    }

    public function test_only_emergency_cancellations_need_rebooking(): void
    {
        $this->assertTrue(CancelReason::EMERGENCY->requiresRebooking());
        $this->assertFalse(CancelReason::PATIENT_CANCELLED->requiresRebooking());
        $this->assertFalse(CancelReason::INCOMPLETE->requiresRebooking());
    }

    public function test_incomplete_is_system_only_and_not_offered_to_the_secretary(): void
    {
        $this->assertNotContains(CancelReason::INCOMPLETE, CancelReason::selectable());
        $this->assertNotContains(CancelReason::EMERGENCY, CancelReason::selectable());
        $this->assertSame([CancelReason::PATIENT_CANCELLED], CancelReason::selectable());
    }

    /**
     * The business week starts Saturday; Carbon numbers Sunday as 0. Getting
     * this wrong silently shifts every clinic's opening hours by a day.
     */
    public function test_the_week_starts_on_saturday_and_round_trips_through_carbon(): void
    {
        $this->assertSame(0, DayOfWeek::SATURDAY->value);
        $this->assertSame(6, DayOfWeek::FRIDAY->value);

        foreach (DayOfWeek::week() as $day) {
            $this->assertSame(
                $day,
                DayOfWeek::fromCarbonDayOfWeek($day->toCarbonDayOfWeek()),
                "Round trip failed for {$day->name}",
            );
        }
    }

    public function test_it_maps_a_real_date_to_the_right_day(): void
    {
        // 8 August 2026 is a Saturday.
        $this->assertSame(DayOfWeek::SATURDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-08')));
        $this->assertSame(DayOfWeek::SUNDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-09')));
        $this->assertSame(DayOfWeek::FRIDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-14')));
    }
}
