<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class WhatsAppMessagingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function booking(bool $optedIn = true): Booking
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'سارة أحمد',
            'whatsapp_opt_in_at' => $optedIn ? now() : null,
        ]);

        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-08-08 09:00', 'Africa/Cairo'))
            ->create(['patient_id' => $patient->id]);
    }

    public function test_it_lists_active_message_templates(): void
    {
        $this->getJson(route('api.v1.message-templates.index'))
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.category', 'utility');
    }

    public function test_the_log_driver_records_and_marks_a_booking_message_sent(): void
    {
        $booking = $this->booking();

        $this->postJson(route('api.v1.bookings.message', $booking), [
            'template_key' => 'appointment_delayed',
        ])
            ->assertOk()
            ->assertJsonPath('data.sent_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $message = OutboundMessage::first();

        $this->assertSame('sent', $message->status);
        $this->assertStringContainsString('سارة أحمد', $message->rendered_body);
        $this->assertStringStartsWith('log_', $message->provider_message_id);
    }

    public function test_patients_without_whatsapp_opt_in_are_skipped(): void
    {
        $booking = $this->booking(optedIn: false);

        $this->postJson(route('api.v1.bookings.message', $booking), [
            'template_key' => 'appointment_delayed',
        ])
            ->assertOk()
            ->assertJsonPath('data.sent_count', 0)
            ->assertJsonPath('data.skipped.0.reason', 'whatsapp_not_opted_in');

        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_day_cancelled_broadcast_cancels_pending_bookings(): void
    {
        $booking = $this->booking();

        $this->postJson(route('api.v1.broadcasts.store'), [
            'template_key' => 'day_cancelled',
            'date' => '2026-08-08',
        ])
            ->assertOk()
            ->assertJsonPath('data.cancelled_count', 1)
            ->assertJsonPath('data.sent_count', 1);

        $booking->refresh();

        $this->assertSame(BookingStatus::CANCELLED, $booking->status);
        $this->assertSame(CancelReason::EMERGENCY, $booking->cancel_reason);
    }
}
