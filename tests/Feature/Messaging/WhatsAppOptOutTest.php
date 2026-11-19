<?php

namespace Tests\Feature\Messaging;

use App\Enums\WhatsAppOptOutSource;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Services\Messaging\MessageSender;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * A patient replying «إيقاف» on WhatsApp stops our messages, end to end
 * through Meta's signed webhook.
 */
class WhatsAppOptOutTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SECRET = 'test-app-secret';

    private const FROM = '201012345678';

    private RecordingMessageSender $sender;

    private Patient $here;

    private Patient $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        config(['services.whatsapp.app_secret' => self::SECRET]);

        $this->sender = new RecordingMessageSender;
        $this->app->instance(MessageSender::class, $this->sender);

        // The same person at two clinics: one WhatsApp number sends for both.
        $this->here = Patient::factory()->create(['clinic_id' => $this->clinic->id, 'phone' => '+'.self::FROM]);
        $this->elsewhere = Patient::factory()->create(['clinic_id' => Clinic::factory()->create()->id, 'phone' => '+'.self::FROM]);
    }

    private function text(string $body, ?string $secret = self::SECRET, string $from = self::FROM): TestResponse
    {
        $payload = ['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => $from,
            'id' => 'wamid.IN',
            'type' => 'text',
            'text' => ['body' => $body],
        ]]]]]]]];
        $body = json_encode($payload);

        $headers = $secret === null ? [] : ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars(
            $headers + ['Content-Type' => 'application/json']
        ), $body);
    }

    private function stopped(Patient $patient): bool
    {
        return $patient->fresh()->whatsapp_opted_out_at !== null;
    }

    public function test_stop_stops_the_number_at_every_clinic_and_says_so_once(): void
    {
        $this->text('إيقاف')->assertOk();

        foreach ([$this->here, $this->elsewhere] as $patient) {
            $fresh = $patient->fresh();
            $this->assertNull($fresh->whatsapp_opt_in_at);
            $this->assertSame(WhatsAppOptOutSource::PATIENT, $fresh->whatsapp_opt_out_source);
        }

        $this->assertSame(1, OutboundMessage::where('template_key', WhatsAppMessagingService::OPT_OUT_REPLY_KEY)->count());
        $this->assertCount(1, $this->sender->texts);
        $this->assertSame(__('patient.whatsapp.stopped_reply', [], 'ar'), $this->sender->texts[0]['body']);
    }

    /** The ways people actually type it. */
    public function test_spellings_of_stop_are_understood(): void
    {
        foreach (['ايقاف', ' إيقاف. ', 'إيقاف الرسائل', 'STOP', 'Stop!', 'إلغاء الاشتراك', 'unsubscribe'] as $i => $word) {
            $this->here->update(['whatsapp_opted_out_at' => null, 'whatsapp_opt_in_at' => now()]);
            $this->elsewhere->update(['whatsapp_opted_out_at' => null]);

            $this->text($word);

            $this->assertTrue($this->stopped($this->here), "«{$word}» did not stop.");
        }
    }

    /** A sentence is somebody talking, not unsubscribing. */
    public function test_other_messages_change_nothing(): void
    {
        foreach (['إيقاف الحجز', 'stop please', 'عايز احجز', 'إلغاء', 'شكرا'] as $word) {
            $this->text($word)->assertOk();
        }

        $this->assertFalse($this->stopped($this->here));
        $this->assertSame([], $this->sender->texts);
    }

    /** Twice, or Meta delivering the same callback again: one reply. */
    public function test_a_second_stop_sends_no_second_reply(): void
    {
        $this->text('إيقاف');
        $this->text('إيقاف');

        $this->assertCount(1, $this->sender->texts);
    }

    public function test_an_unknown_number_gets_nothing(): void
    {
        $this->text('إيقاف', from: '201099999999')->assertOk();

        $this->assertSame([], $this->sender->texts);
        $this->assertFalse($this->stopped($this->here));
    }

    public function test_an_unsigned_stop_changes_nothing(): void
    {
        $this->text('إيقاف', secret: null)->assertForbidden();

        $this->assertFalse($this->stopped($this->here));
    }

    /** After a stop, nothing else is sent to them. */
    public function test_after_a_stop_booking_messages_are_not_sent(): void
    {
        $this->text('إيقاف');

        $booking = Booking::factory()->forClinic($this->clinic)->create(['patient_id' => $this->here->id]);
        $this->clinic->update(['whatsapp_enabled' => true]);

        $this->assertNull(app(WhatsAppMessagingService::class)->sendConfirmation($this->clinic, $booking->fresh()));
    }
}
