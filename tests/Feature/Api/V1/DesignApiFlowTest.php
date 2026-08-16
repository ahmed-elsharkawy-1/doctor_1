<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\VisitType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class DesignApiFlowTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(DatabaseSeeder::class);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        $this->visitType = $this->clinic->visitTypes()->first();
        $this->visitType->update(['price' => 300, 'duration_minutes' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_main_design_api_flow_is_valid(): void
    {
        $token = $this->postJson(route('api.v1.auth.login'), [
            'email' => $this->owner->email,
            'password' => 'password',
            'device_name' => 'integration-test',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', $this->clinic->name)
            ->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token);

        $this->getJson(route('api.v1.auth.me'))
            ->assertOk()
            ->assertJsonPath('data.clinic.id', $this->clinic->id)
            ->assertJsonPath('data.abilities', $this->owner->role->abilities());

        $availableBefore = $this->slots();
        $this->assertTrue(collect($availableBefore)->firstWhere('start_time.value', '09:00')['is_available']);

        $booking = $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'سارة أحمد علي',
            'phone' => '01012225521',
            'age' => 32,
            'whatsapp_opt_in' => true,
            'visit_type_id' => $this->visitType->id,
            'date' => '2026-08-08',
            'start_time' => '09:00',
            'notes' => 'ملاحظة من الشاشة الجديدة',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'booked')
            ->assertJsonPath('data.patient.name', 'سارة أحمد علي')
            ->assertJsonPath('data.patient.phone.display', '01012225521')
            ->assertJsonPath('data.price.value', '300.00')
            ->json('data');

        $patientId = $booking['patient']['id'];
        $this->assertSame(Patient::codeForId($patientId), $booking['patient']['code']);

        $takenAfterBooking = $this->slots();
        $this->assertFalse(collect($takenAfterBooking)->firstWhere('start_time.value', '09:00')['is_available']);

        $this->getJson(route('api.v1.home'))
            ->assertOk()
            ->assertJsonPath('data.today.counts.total', 1)
            ->assertJsonPath('data.upcoming.0.id', $booking['id']);

        $card = $this->calendarCard($booking['id']);

        $this->assertSame('arrived', $card['next_status']['value']);
        $this->assertSame(['call', 'whatsapp', 'edit', 'no_show', 'cancel'], $card['available_actions']);
        $this->assertArrayNotHasKey('queue_position', $card);

        $this->postJson(route('api.v1.bookings.message', $booking['id']), [
            'template_key' => 'appointment_delayed',
        ])
            ->assertOk()
            ->assertJsonPath('data.sent_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $this->assertSame('sent', OutboundMessage::firstOrFail()->status);

        $this->postJson(route('api.v1.bookings.status', $booking['id']), ['to' => 'arrived'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'arrived')
            ->assertJsonPath('data.arrived_at', '08:00')
            ->assertJsonMissingPath('data.queue_position');

        $this->postJson(route('api.v1.bookings.status', $booking['id']), ['to' => 'with_doctor'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'with_doctor');

        $withDoctorCard = $this->calendarCard($booking['id']);

        $this->assertSame(['whatsapp', 'cancel'], $withDoctorCard['available_actions']);
        $this->assertSame('done', $withDoctorCard['next_status']['value']);

        $this->postJson(route('api.v1.bookings.status', $booking['id']), ['to' => 'no_show'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');

        $this->postJson(route('api.v1.bookings.cancel', $booking['id']), [
            'reason' => 'patient_cancelled',
        ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled')
            ->assertJsonPath('data.cancel_reason.value', CancelReason::PATIENT_CANCELLED->value)
            ->assertJsonMissingPath('data.queue_position');

        $freedAfterCancel = $this->slots();
        $this->assertTrue(collect($freedAfterCancel)->firstWhere('start_time.value', '09:00')['is_available']);

        $this->getJson(route('api.v1.patients.show', $patientId))
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patientId)
            ->assertJsonPath('data.summary.visits_count', 0)
            ->assertJsonPath('data.summary.cancelled_count', 1)
            ->assertJsonPath('data.visits.0.status.value', 'cancelled')
            ->assertJsonPath('data.visits.0.cancel_reason.value', CancelReason::PATIENT_CANCELLED->value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slots(): array
    {
        return $this->getJson(route('api.v1.slots', [
            'date' => '2026-08-08',
            'visit_type_id' => $this->visitType->id,
        ]))
            ->assertOk()
            ->json('data.slots');
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarCard(int $bookingId): array
    {
        $data = $this->getJson(route('api.v1.bookings.calendar', [
            'from' => '2026-08-08',
            'to' => '2026-08-08',
        ]))
            ->assertOk()
            ->json('data');

        $card = collect($data['bookings']['2026-08-08'])->firstWhere('id', $bookingId);

        $this->assertIsArray($card);

        return $card;
    }
}
