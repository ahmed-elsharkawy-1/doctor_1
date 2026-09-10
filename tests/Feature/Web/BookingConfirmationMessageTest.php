<?php

namespace Tests\Feature\Web;

use App\Enums\BookingKind;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Livewire\App\NewBooking;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\VisitType;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class BookingConfirmationMessageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::THURSDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->visitType = $this->clinic->visitTypes()->active()->firstOrFail();

        $this->seedConfirmationTemplate();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedConfirmationTemplate(): void
    {
        MessageTemplate::updateOrCreate(
            ['key' => WhatsAppMessagingService::CONFIRMATION_KEY],
            [
                'category' => 'utility',
                'is_broadcast' => false,
                'body_ar' => 'مرحباً {{1}}، تم تأكيد حجزك في {{2}} يوم {{3}}. تقدر تتابع دورك من هنا: {{4}}',
                'provider_template_name' => WhatsAppMessagingService::CONFIRMATION_KEY,
                'is_active' => true,
            ],
        );
    }

    private function firstFreeSlot(): string
    {
        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::now($this->clinic->timezone),
            $this->visitType,
        );

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        $this->fail('No free slot today.');
    }

    private function book(bool $optIn = true): void
    {
        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->set('patientName', 'سارة أحمد')
            ->set('phone', '01001234501')
            ->set('whatsappOptIn', $optIn)
            ->call('selectSlot', $this->firstFreeSlot())
            ->call('save')
            ->assertSet('failed', false);
    }

    public function test_booking_queues_a_confirmation_for_the_patient(): void
    {
        $this->book();

        $message = OutboundMessage::latest('id')->first();

        $this->assertNotNull($message);
        $this->assertSame(WhatsAppMessagingService::CONFIRMATION_KEY, $message->template_key);
        $this->assertSame($this->clinic->id, $message->clinic_id);
    }

    public function test_the_confirmation_carries_the_tracking_link(): void
    {
        $this->book();

        $booking = Booking::latest('id')->first();
        $message = OutboundMessage::latest('id')->first();

        // The approved template carries the link on its URL button, and Meta
        // appends the suffix to a fixed base — so the path travels there, not
        // in the text.
        $this->assertSame('booking/'.$booking->tracking_token, $message->button_suffix);
        $this->assertStringEndsWith($message->button_suffix, $booking->trackingUrl());
    }

    /**
     * The six parameters the approved template declares, in its order:
     * patient, date, time, doctor, address, arrival lead.
     */
    public function test_the_confirmation_fills_every_approved_parameter(): void
    {
        $this->clinic->update([
            'address' => '12 شارع مصدق، الدقي',
            'patient_arrival_lead_minutes' => 15,
        ]);

        $this->book();

        $booking = Booking::latest('id')->first();
        $variables = OutboundMessage::latest('id')->first()->variables;

        $this->assertCount(6, $variables);
        $this->assertSame('سارة أحمد', $variables[0]);
        $this->assertSame($booking->visit_date->locale('ar')->isoFormat('D MMMM YYYY'), $variables[1]);
        $this->assertStringContainsString($booking->start_at->format('h:i'), $variables[2]);
        $this->assertSame($this->clinic->doctor->name, $variables[3]);
        $this->assertSame('12 شارع مصدق، الدقي', $variables[4]);
        $this->assertSame('15 دقيقة', $variables[5]);

        // Meta rejects a parameter that is empty or carries a newline.
        foreach ($variables as $value) {
            $this->assertNotSame('', trim((string) $value));
            $this->assertDoesNotMatchRegularExpression('/[\n\r\t]/', (string) $value);
        }
    }

    public function test_no_placeholder_is_left_unreplaced(): void
    {
        $this->book();

        $this->assertStringNotContainsString(
            '{{',
            OutboundMessage::latest('id')->first()->rendered_body,
        );
    }

    public function test_a_patient_who_did_not_opt_in_is_not_messaged(): void
    {
        $this->book(optIn: false);

        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(1, Booking::count());
    }

    public function test_a_missing_template_never_costs_the_booking(): void
    {
        MessageTemplate::where('key', WhatsAppMessagingService::CONFIRMATION_KEY)->delete();

        $this->book();

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_an_emergency_booking_is_confirmed_with_its_date_alone(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->call('selectKind', BookingKind::EMERGENCY->value)
            ->set('patientName', 'حالة طارئة')
            ->set('phone', '01001234509')
            ->call('save')
            ->assertSet('failed', false);

        $message = OutboundMessage::latest('id')->first();

        // No slot to name. The time parameter still has to say something —
        // Meta rejects an empty one — so it says there is no set time.
        $this->assertNotNull($message);
        $this->assertSame(__('messages.fallback.no_time'), $message->variables[2]);
        $this->assertNotSame('', trim((string) $message->variables[2]));
    }

    public function test_the_confirmation_is_never_offered_as_a_broadcast(): void
    {
        // Otherwise a secretary could send a whole day someone else's
        // confirmation, complete with someone else's tracking link.
        $offered = app(WhatsAppMessagingService::class)->templates()
            ->pluck('key')
            ->all();

        $this->assertNotContains(WhatsAppMessagingService::CONFIRMATION_KEY, $offered);
    }

    public function test_the_confirmation_cannot_be_broadcast_by_key_either(): void
    {
        $this->expectException(ApiException::class);

        app(WhatsAppMessagingService::class)->broadcast(
            $this->clinic,
            WhatsAppMessagingService::CONFIRMATION_KEY,
        );
    }

    public function test_the_confirmation_is_linked_to_its_booking_and_patient(): void
    {
        $this->book();

        $booking = Booking::latest('id')->first();
        $message = OutboundMessage::latest('id')->first();

        $this->assertSame($booking->id, $message->booking_id);
        $this->assertSame($booking->patient_id, $message->patient_id);
        $this->assertSame(
            Patient::latest('id')->first()->id,
            $message->patient_id,
        );
    }
}
