<?php

namespace Tests\Feature\Api\V1\Queue;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\PatientResponse;
use App\Enums\PatientResponseSource;
use App\Models\Booking;
use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The secretary called a patient who never answered the WhatsApp reminder,
 * and records by hand that they are coming.
 */
class ConfirmAttendanceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        $this->booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-08 09:00', 'Africa/Cairo'))
            ->create();

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function confirm(Booking $booking)
    {
        return $this->postJson(route('api.v1.bookings.confirm-attendance', $booking));
    }

    public function test_the_secretary_confirms_a_patient_who_did_not_answer_on_whatsapp(): void
    {
        $this->confirm($this->booking)
            ->assertOk()
            ->assertJsonPath('message', 'تم تأكيد الحضور')
            ->assertJsonPath('data.status.value', 'booked')
            ->assertJsonPath('data.patient_response.value', 'confirmed')
            ->assertJsonPath('data.patient_response.display', 'أكدت الحضور بالتليفون')
            ->assertJsonPath('data.patient_response_source.value', 'clinic')
            ->assertJsonPath('data.patient_response_source.display', 'العيادة');

        $booking = $this->booking->fresh();

        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        $this->assertSame(PatientResponse::CONFIRMED, $booking->patient_response);
        $this->assertSame(PatientResponseSource::CLINIC, $booking->patient_response_source);
        $this->assertSame($this->secretary->id, $booking->patient_response_by);
        $this->assertNotNull($booking->patient_responded_at);
    }

    public function test_it_sends_the_patient_nothing(): void
    {
        $this->confirm($this->booking)->assertOk();

        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_the_card_offers_the_action_until_the_patient_is_confirmed(): void
    {
        $card = $this->getJson(route('api.v1.bookings.calendar', ['from' => '2026-08-08', 'to' => '2026-08-08']))
            ->assertOk()
            ->json('data.bookings.2026-08-08.0');

        $this->assertContains('confirm_attendance', $card['available_actions']);

        $this->assertNotContains(
            'confirm_attendance',
            $this->confirm($this->booking)->assertOk()->json('data.available_actions'),
        );
    }

    public function test_a_whatsapp_confirmation_is_left_as_the_patients_own(): void
    {
        $answeredAt = Carbon::parse('2026-08-08 07:30:00', 'Africa/Cairo');

        $this->booking->update([
            'patient_response' => PatientResponse::CONFIRMED,
            'patient_responded_at' => $answeredAt,
            'patient_response_source' => PatientResponseSource::WHATSAPP,
        ]);

        $this->confirm($this->booking)
            ->assertOk()
            ->assertJsonPath('data.patient_response.display', 'أكدت الحضور')
            ->assertJsonPath('data.patient_response_source.value', 'whatsapp');

        $booking = $this->booking->fresh();

        $this->assertSame(PatientResponseSource::WHATSAPP, $booking->patient_response_source);
        $this->assertNull($booking->patient_response_by);
        $this->assertTrue($booking->patient_responded_at->equalTo($answeredAt));
    }

    public function test_confirming_twice_keeps_the_first_time(): void
    {
        $this->confirm($this->booking)->assertOk();
        $first = $this->booking->fresh()->patient_responded_at;

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:30:00', 'Africa/Cairo'));

        $this->confirm($this->booking)->assertOk();

        $this->assertTrue($this->booking->fresh()->patient_responded_at->equalTo($first));
    }

    public function test_only_a_booking_still_booked_can_be_confirmed(): void
    {
        $bookings = [
            'arrived' => Booking::factory()->forClinic($this->clinic)->arrived()->create(),
            'cancelled' => Booking::factory()->forClinic($this->clinic)->cancelled()->create(),
            'no_show' => Booking::factory()->forClinic($this->clinic)->noShow()->create(),
        ];

        foreach ($bookings as $status => $booking) {
            $this->confirm($booking)
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'BOOKING_NOT_CONFIRMABLE')
                ->assertJsonPath('error.details.status', $status);

            $this->assertNull($booking->fresh()->patient_response_source);
        }
    }

    public function test_another_clinics_booking_is_not_found(): void
    {
        $theirs = Booking::factory()->forClinic($this->otherClinic())->create();

        $this->confirm($theirs)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');

        $this->assertNull($theirs->fresh()->patient_response);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->app['auth']->forgetGuards();

        $this->confirm($this->booking)->assertUnauthorized();
    }
}
