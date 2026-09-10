<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The tracking link reaches the patient without anyone pressing anything.
 * A booking taken on the mobile app must behave exactly like one taken on the
 * web — this is the path that had no send at all.
 */
class BookingConfirmationIsAutomaticTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        MessageTemplate::updateOrCreate(
            ['key' => WhatsAppMessagingService::CONFIRMATION_KEY],
            [
                'category' => 'utility',
                'is_broadcast' => false,
                'body_ar' => 'مرحباً {{1}}، تم تأكيد حجزك في {{2}} يوم {{3}}. تابع دورك: {{4}}',
                'provider_template_name' => WhatsAppMessagingService::CONFIRMATION_KEY,
                'is_active' => true,
            ],
        );

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.v1.bookings.store'), array_merge([
            'patient_name' => 'سارة أحمد',
            'phone' => '01099500001',
            'visit_type_id' => $this->clinic->visitTypes()->active()->first()->id,
            'date' => '2026-09-12',
            'start_time' => '09:00',
        ], $overrides));
    }

    public function test_a_booking_made_through_the_api_sends_the_confirmation(): void
    {
        $this->book()->assertCreated();

        $message = OutboundMessage::latest('id')->first();

        $this->assertNotNull($message, 'The mobile app must not need a button to reach the patient.');
        $this->assertSame(WhatsAppMessagingService::CONFIRMATION_KEY, $message->template_key);
    }

    public function test_the_message_carries_the_tracking_link(): void
    {
        $this->book()->assertCreated();

        $booking = Booking::latest('id')->first();
        $message = OutboundMessage::latest('id')->first();

        // The approved template puts the link on its URL button rather than in
        // the text, and Meta appends the suffix to a fixed base — so the whole
        // path travels, not just the token.
        $this->assertSame('booking/'.$booking->tracking_token, $message->button_suffix);
        $this->assertStringEndsWith($message->button_suffix, $booking->trackingUrl());
    }

    public function test_whatsapp_consent_is_assumed_when_the_caller_says_nothing(): void
    {
        // The mobile client does not send the flag; the patient just gave the
        // clinic their number to be contacted on.
        $this->book()->assertCreated();

        $this->assertNotNull(Patient::latest('id')->first()->whatsapp_opt_in_at);
        $this->assertSame(1, OutboundMessage::count());
    }

    public function test_a_returning_patient_without_consent_is_granted_it(): void
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => '+201099500001',
            'whatsapp_opt_in_at' => null,
        ]);

        $this->book()->assertCreated();

        // Otherwise every patient predating the field is unreachable forever.
        $this->assertNotNull($patient->fresh()->whatsapp_opt_in_at);
        $this->assertSame(1, OutboundMessage::count());
    }

    public function test_a_patient_who_declined_is_still_not_messaged(): void
    {
        $this->book(['whatsapp_opt_in' => false])->assertCreated();

        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_a_missing_template_never_costs_the_booking(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::CONFIRMATION_KEY)->delete();

        $this->book()->assertCreated();

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, OutboundMessage::count());
    }
}
