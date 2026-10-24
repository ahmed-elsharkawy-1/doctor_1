<?php

namespace Tests\Feature\Messaging;

use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PatientResponse;
use App\Events\ReminderResponseReceived;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Services\Messaging\MessageSender;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * The patient's two buttons, end to end through Meta's webhook.
 *
 * The signed callback is the only thing that reaches this path, and the
 * number it came from must match the number the reminder went to. Anything
 * else is ignored silently — there is nobody to tell and nothing to retry.
 */
class ReminderReplyTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SECRET = 'test-app-secret';

    private const DAY = '2026-09-03';

    private RecordingMessageSender $sender;

    private Booking $booking;

    private OutboundMessage $reminder;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 11:30:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'phone' => '+201022334455',
            'reminder_time' => '11:00',
        ]);

        config([
            'services.whatsapp.app_secret' => self::SECRET,
        ]);

        $this->sender = new RecordingMessageSender;
        $this->app->instance(MessageSender::class, $this->sender);

        $this->booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 15:00', 'Africa/Cairo'))
            ->create();

        $this->booking->patient->update(['phone' => '+201012345678']);

        $this->reminder = OutboundMessage::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->booking->patient_id,
            'booking_id' => $this->booking->id,
            'template_key' => WhatsAppMessagingService::REMINDER_KEY,
            'type' => 'template',
            'rendered_body' => 'reminder',
            'status' => 'sent',
            'provider_message_id' => 'wamid.REMINDER',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function reply(array $message, ?string $secret = self::SECRET): TestResponse
    {
        $payload = ['entry' => [['changes' => [['value' => ['messages' => [$message]]]]]]];
        $body = json_encode($payload);

        $headers = $secret === null
            ? []
            : ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars(
            $headers + ['Content-Type' => 'application/json']
        ), $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function button(string $payload, string $text, string $from = '201012345678', ?string $context = 'wamid.REMINDER'): array
    {
        $message = [
            'from' => $from,
            'id' => 'wamid.INCOMING',
            'timestamp' => (string) now()->timestamp,
            'type' => 'button',
            'button' => ['payload' => $payload, 'text' => $text],
        ];

        if ($context !== null) {
            $message['context'] = ['id' => $context];
        }

        return $message;
    }

    public function test_confirm_marks_the_patient_response_and_keeps_the_booking_booked(): void
    {
        Event::fake([ReminderResponseReceived::class]);

        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();

        $booking = $this->booking->fresh();

        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        $this->assertSame(PatientResponse::CONFIRMED, $booking->patient_response);
        $this->assertNotNull($booking->patient_responded_at);
        $this->assertNull($booking->cancel_reason);

        // The follow-up is free text, inside the session, and carries the link.
        $this->assertCount(1, $this->sender->texts);
        $this->assertStringContainsString($booking->trackingUrl(), $this->sender->texts[0]['body']);
        $this->assertStringContainsString('تم تأكيد حضورك', $this->sender->texts[0]['body']);

        $followUp = OutboundMessage::where('template_key', WhatsAppMessagingService::REMINDER_CONFIRMED_KEY)->sole();
        $this->assertSame('text', $followUp->type);
        $this->assertSame('sent', $followUp->status);

        Event::assertDispatched(ReminderResponseReceived::class);
    }

    public function test_cancel_cancels_the_booking_frees_the_slot_and_sends_the_clinic_phone(): void
    {
        Event::fake([ReminderResponseReceived::class]);

        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();

        $booking = $this->booking->fresh();

        $this->assertSame(BookingStatus::CANCELLED, $booking->status);
        $this->assertSame(CancelReason::PATIENT_CANCELLED, $booking->cancel_reason);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame(PatientResponse::CANCELLED, $booking->patient_response);

        $this->assertCount(1, $this->sender->texts);
        $this->assertStringContainsString('+201022334455', $this->sender->texts[0]['body']);
        $this->assertStringContainsString('تم إلغاء حجزك', $this->sender->texts[0]['body']);

        Event::assertDispatched(ReminderResponseReceived::class);
    }

    public function test_the_arabic_button_text_is_enough_without_a_known_payload(): void
    {
        $this->reply($this->button('some-new-payload', 'تأكيد الحضور'))->assertOk();

        $this->assertSame(PatientResponse::CONFIRMED, $this->booking->fresh()->patient_response);
    }

    public function test_interactive_button_replies_are_understood_too(): void
    {
        $this->reply([
            'from' => '201012345678',
            'id' => 'wamid.INCOMING',
            'timestamp' => (string) now()->timestamp,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button_reply',
                'button_reply' => ['id' => 'cancel_booking', 'title' => 'إلغاء الحجز'],
            ],
            'context' => ['id' => 'wamid.REMINDER'],
        ])->assertOk();

        $this->assertSame(BookingStatus::CANCELLED, $this->booking->fresh()->status);
    }

    public function test_a_reply_from_another_number_is_ignored(): void
    {
        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور', from: '201099999999'))->assertOk();

        $this->assertNull($this->booking->fresh()->patient_response);
        $this->assertCount(0, $this->sender->texts);
    }

    /** Even if the message id quoted back is real, a different number cannot answer it. */
    public function test_a_known_message_id_from_another_number_is_refused(): void
    {
        $this->reply($this->button('cancel_booking', 'إلغاء الحجز', from: '201099999999'))->assertOk();

        $this->assertSame(BookingStatus::BOOKED, $this->booking->fresh()->status);
    }

    public function test_replies_are_ignored_once_the_booking_is_no_longer_booked(): void
    {
        foreach ([BookingStatus::ARRIVED, BookingStatus::WITH_DOCTOR, BookingStatus::DONE, BookingStatus::CANCELLED, BookingStatus::NO_SHOW] as $status) {
            $this->booking->update(['status' => $status]);

            $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();

            $this->assertNull($this->booking->fresh()->patient_response);
        }

        $this->assertCount(0, $this->sender->texts);
    }

    /** A free-text reply is not one of the two buttons, so nothing is processed. */
    public function test_a_free_text_reply_is_ignored(): void
    {
        $this->reply([
            'from' => '201012345678',
            'id' => 'wamid.INCOMING',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'تأكيد الحضور'],
            'context' => ['id' => 'wamid.REMINDER'],
        ])->assertOk();

        $this->assertNull($this->booking->fresh()->patient_response);
    }

    public function test_cancel_after_the_visit_time_has_passed_is_ignored(): void
    {
        Carbon::setTestNow(Carbon::parse(self::DAY.' 16:00:00', 'Africa/Cairo'));

        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();

        $booking = $this->booking->fresh();

        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        $this->assertNull($booking->patient_response);
    }

    /** Visit at 15:00, cutoff 60: at 14:30 Cancel keeps the booking and sends the phone. */
    public function test_cancel_inside_the_clinics_cutoff_is_refused_with_the_clinic_phone(): void
    {
        Event::fake([ReminderResponseReceived::class]);
        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 60]);
        Carbon::setTestNow(Carbon::parse(self::DAY.' 14:30:00', 'Africa/Cairo'));

        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();

        $booking = $this->booking->fresh();

        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        $this->assertNull($booking->patient_response);
        $this->assertNull($booking->cancelled_at);

        $this->assertCount(1, $this->sender->texts);
        $this->assertStringContainsString('مينفعش تلغي الحجز', $this->sender->texts[0]['body']);
        $this->assertStringContainsString('60 دقيقة', $this->sender->texts[0]['body']);
        $this->assertStringContainsString('+201022334455', $this->sender->texts[0]['body']);
        $this->assertSame(
            WhatsAppMessagingService::REMINDER_CANCEL_REFUSED_KEY,
            OutboundMessage::where('type', 'text')->sole()->template_key,
        );

        // Nothing changed, so there is nothing for the secretary to hear.
        Event::assertNotDispatched(ReminderResponseReceived::class);
    }

    /** The cutoff is the clinic's own: 120 refuses at 13:30, 60 would not have. */
    public function test_the_cutoff_follows_the_clinics_setting(): void
    {
        Carbon::setTestNow(Carbon::parse(self::DAY.' 13:30:00', 'Africa/Cairo'));

        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 120]);
        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();
        $this->assertSame(BookingStatus::BOOKED, $this->booking->fresh()->status);

        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 60]);
        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();
        $this->assertSame(BookingStatus::CANCELLED, $this->booking->fresh()->status);
    }

    public function test_a_cutoff_of_zero_allows_cancel_until_the_visit_starts(): void
    {
        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 0]);
        Carbon::setTestNow(Carbon::parse(self::DAY.' 14:55:00', 'Africa/Cairo'));

        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();

        $this->assertSame(BookingStatus::CANCELLED, $this->booking->fresh()->status);
    }

    /** The cutoff limits cancelling only; confirming is always welcome. */
    public function test_confirm_inside_the_cutoff_still_counts(): void
    {
        $this->clinic->update(['whatsapp_cancel_cutoff_minutes' => 60]);
        Carbon::setTestNow(Carbon::parse(self::DAY.' 14:30:00', 'Africa/Cairo'));

        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();

        $this->assertSame(PatientResponse::CONFIRMED, $this->booking->fresh()->patient_response);
    }

    public function test_the_last_valid_reply_wins_while_the_booking_is_still_booked(): void
    {
        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();
        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'))->assertOk();

        $booking = $this->booking->fresh();

        $this->assertSame(BookingStatus::CANCELLED, $booking->status);
        $this->assertSame(PatientResponse::CANCELLED, $booking->patient_response);
    }

    /** A double tap, or Meta redelivering the callback, is one answer, not two. */
    public function test_the_same_answer_twice_sends_one_follow_up(): void
    {
        Event::fake([ReminderResponseReceived::class]);

        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();
        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();

        $this->assertSame(PatientResponse::CONFIRMED, $this->booking->fresh()->patient_response);
        $this->assertCount(1, $this->sender->texts);
        Event::assertDispatchedTimes(ReminderResponseReceived::class, 1);
    }

    /** Meta normally quotes the message back; when it does not, the number still identifies it. */
    public function test_a_reply_without_the_quoted_message_id_still_finds_the_reminder(): void
    {
        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور', context: null))->assertOk();

        $this->assertSame(PatientResponse::CONFIRMED, $this->booking->fresh()->patient_response);
    }

    public function test_an_unsigned_reply_changes_nothing(): void
    {
        $this->reply($this->button('cancel_booking', 'إلغاء الحجز'), secret: null)->assertForbidden();

        $this->assertSame(BookingStatus::BOOKED, $this->booking->fresh()->status);
    }

    /** A missing booking row must not make the webhook fail. */
    public function test_a_reply_to_a_deleted_booking_is_simply_ignored(): void
    {
        $this->reminder->update(['booking_id' => null]);

        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();

        $this->assertNull($this->booking->fresh()->patient_response);
    }

    /** The follow-up job is what the queue would run; prove it is queued even outside sync. */
    public function test_the_follow_up_is_dispatched_through_the_message_job(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);

        $this->reply($this->button('confirm_attendance', 'تأكيد الحضور'))->assertOk();

        Queue::assertPushed(SendWhatsAppMessage::class);
        $this->assertCount(0, $this->sender->texts);
    }
}
