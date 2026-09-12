<?php

namespace Tests\Feature\Messaging;

use App\Models\Booking;
use App\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
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
     */
    private function receipt(array $statuses, ?string $secret = self::SECRET): TestResponse
    {
        $payload = ['entry' => [['changes' => [['value' => ['statuses' => $statuses]]]]]];
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
