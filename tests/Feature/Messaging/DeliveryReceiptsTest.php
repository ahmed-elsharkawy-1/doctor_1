<?php

namespace Tests\Feature\Messaging;

use App\Models\Booking;
use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * What Meta tells us after it has taken a message.
 *
 * Sending only ever proved Meta accepted it. Whether it reached the patient,
 * or was silently dropped for exceeding a marketing cap, arrives here and
 * nowhere else — which is why the endpoint has to be both trustworthy and
 * unfussy about the order things turn up in.
 */
class DeliveryReceiptsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const SECRET = 'test-app-secret';

    private OutboundMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 16:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        config([
            'services.whatsapp.app_secret' => self::SECRET,
            'services.whatsapp.verify_token' => 'test-verify-token',
        ]);

        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-12 17:00', $this->clinic->timezone))
            ->create();

        $this->message = OutboundMessage::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $booking->patient_id,
            'booking_id' => $booking->id,
            'template_key' => 'booking_confirmed',
            'rendered_body' => 'x',
            'status' => 'sent',
            'provider_message_id' => 'wamid.TEST',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     * @param  list<array<string, mixed>>  $messages  patients' replies, if any
     */
    private function receipt(array $statuses, ?string $secret = self::SECRET, array $messages = []): TestResponse
    {
        $value = ['statuses' => $statuses] + ($messages === [] ? [] : ['messages' => $messages]);
        $payload = ['entry' => [['changes' => [['value' => $value]]]]];
        $body = json_encode($payload);

        $headers = $secret === null
            ? []
            : ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars(
            $headers + ['Content-Type' => 'application/json']
        ), $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Only Meta gets to say
    |--------------------------------------------------------------------------
    */

    public function test_an_unsigned_callback_is_refused(): void
    {
        $this->receipt([['id' => 'wamid.TEST', 'status' => 'delivered']], secret: null)
            ->assertForbidden();

        $this->assertSame('sent', $this->message->fresh()->status);
    }

    public function test_a_callback_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->receipt([['id' => 'wamid.TEST', 'status' => 'delivered']], secret: 'not-the-secret')
            ->assertForbidden();

        $this->assertSame('sent', $this->message->fresh()->status);
    }

    /**
     * Without the secret the signature cannot be checked at all, so the
     * endpoint must not act — an open one would let anyone mark any message
     * delivered, which is the one thing this exists to be believed about.
     */
    public function test_it_refuses_everything_while_no_secret_is_configured(): void
    {
        config(['services.whatsapp.app_secret' => '']);

        $this->receipt([['id' => 'wamid.TEST', 'status' => 'delivered']])->assertForbidden();

        $this->assertSame('sent', $this->message->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Recording what happened
    |--------------------------------------------------------------------------
    */

    public function test_a_delivered_receipt_is_recorded(): void
    {
        $this->receipt([[
            'id' => 'wamid.TEST',
            'status' => 'delivered',
            'timestamp' => (string) Carbon::parse('2026-09-12 16:01:00')->timestamp,
        ]])->assertOk();

        $message = $this->message->fresh();

        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    /**
     * Meta's timestamp is an instant; the table holds the app's wall clock,
     * as `sent_at` does. Stored as UTC it read two hours before the send.
     */
    public function test_receipt_times_are_stored_on_the_same_clock_as_the_send(): void
    {
        $this->receipt([[
            'id' => 'wamid.TEST',
            'status' => 'read',
            'timestamp' => (string) Carbon::parse('2026-09-12 16:01:00', 'Africa/Cairo')->timestamp,
        ]])->assertOk();

        $message = $this->message->fresh();

        $this->assertSame('2026-09-12 16:01:00', $message->read_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 16:01:00', $message->delivered_at->format('Y-m-d H:i:s'));
    }

    public function test_a_failure_records_metas_own_reason(): void
    {
        $this->receipt([[
            'id' => 'wamid.TEST',
            'status' => 'failed',
            'errors' => [[
                'code' => 131049,
                'title' => 'This message was not delivered to maintain healthy ecosystem engagement.',
            ]],
        ]])->assertOk();

        $message = $this->message->fresh();

        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('131049', $message->error);
        $this->assertStringContainsString('healthy ecosystem', $message->error);
    }

    /**
     * Meta promises neither order nor both callbacks, so a read message counts
     * as delivered even if that receipt never came.
     */
    public function test_read_implies_delivered(): void
    {
        $this->receipt([['id' => 'wamid.TEST', 'status' => 'read']])->assertOk();

        $message = $this->message->fresh();

        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->read_at);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_a_late_delivered_receipt_never_walks_a_read_message_back(): void
    {
        $this->receipt([['id' => 'wamid.TEST', 'status' => 'read']])->assertOk();
        $this->receipt([['id' => 'wamid.TEST', 'status' => 'delivered']])->assertOk();

        $this->assertSame('read', $this->message->fresh()->status);
    }

    public function test_a_receipt_for_something_we_never_sent_is_simply_ignored(): void
    {
        $this->receipt([['id' => 'wamid.SOMEONE-ELSE', 'status' => 'delivered']])->assertOk();

        $this->assertSame('sent', $this->message->fresh()->status);
    }

    /**
     * Meta retries anything it does not see a 200 for. A payload we cannot use
     * is not worth being retried about for a day.
     */
    public function test_a_payload_it_makes_no_sense_of_still_answers_ok(): void
    {
        $this->receipt([['nothing' => 'useful']])->assertOk();
        $this->receipt([])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The callback log
    |--------------------------------------------------------------------------
    |
    | One line per callback in a file on the storage volume, so whether Meta
    | is calling at all survives a deploy. Counts only — never a phone number
    | or a message body.
    */

    public function test_every_accepted_callback_leaves_one_line_with_its_counts(): void
    {
        $log = $this->spyOnWebhookLog();

        $this->receipt([
            ['id' => 'wamid.TEST', 'status' => 'delivered', 'recipient_id' => '201001234567'],
            ['id' => 'wamid.SOMEONE-ELSE', 'status' => 'read'],
        ], messages: [
            ['from' => '201001234567', 'type' => 'text', 'text' => ['body' => 'hello']],
        ])->assertOk();

        $log->shouldHaveReceived('info')->once()->with('callback', [
            'statuses' => 2,
            'matched' => 1,
            'states' => ['delivered' => 1, 'read' => 1],
            'messages' => 1,
        ]);
    }

    public function test_a_refused_callback_is_logged_too(): void
    {
        $log = $this->spyOnWebhookLog();

        $this->receipt([['id' => 'wamid.TEST', 'status' => 'delivered']], secret: 'not-the-secret')
            ->assertForbidden();

        $log->shouldHaveReceived('warning')->once()->with('refused', ['reason' => 'bad_signature']);
        $log->shouldNotHaveReceived('info');
    }

    public function test_a_refusal_for_a_missing_secret_says_so(): void
    {
        config(['services.whatsapp.app_secret' => '']);
        $log = $this->spyOnWebhookLog();

        $this->receipt([['id' => 'wamid.TEST', 'status' => 'delivered']])->assertForbidden();

        $log->shouldHaveReceived('warning')->once()->with('refused', ['reason' => 'no_app_secret']);
    }

    public function test_the_handshake_is_logged_with_its_outcome(): void
    {
        $log = $this->spyOnWebhookLog();

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=1');
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=1');

        $log->shouldHaveReceived('warning')->once()->with('handshake', ['ok' => false]);
        $log->shouldHaveReceived('info')->once()->with('handshake', ['ok' => true]);
    }

    public function test_the_log_has_its_own_file_kept_across_deploys(): void
    {
        $channel = config('logging.channels.whatsapp_webhook');

        $this->assertSame('daily', $channel['driver']);
        $this->assertSame(storage_path('logs/whatsapp-webhook.log'), $channel['path']);
    }

    private function spyOnWebhookLog(): MockInterface
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('whatsapp_webhook')->andReturn($logger);

        return $logger;
    }

    /*
    |--------------------------------------------------------------------------
    | The handshake
    |--------------------------------------------------------------------------
    */

    public function test_the_handshake_echoes_the_challenge(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_the_handshake_refuses_a_wrong_token(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
            ->assertForbidden();
    }
}
