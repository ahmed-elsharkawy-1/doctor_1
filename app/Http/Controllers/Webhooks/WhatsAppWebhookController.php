<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Messaging\DeliveryReceipts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Meta's delivery receipts.
 *
 * Unauthenticated by necessity — Meta has no credentials of ours to present —
 * so authenticity rests entirely on the signature it puts on every callback.
 * Without the app secret that signature cannot be checked, and the endpoint
 * refuses to act rather than trusting whatever arrives. That is deliberate:
 * an open endpoint here would let a stranger mark any message delivered, or
 * failed, and the whole point of this is to be believed.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly DeliveryReceipts $receipts) {}

    /**
     * The one-time handshake Meta performs when the callback URL is saved.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.whatsapp.verify_token');
        $offered = (string) $request->query('hub_verify_token');

        if ($expected === '' || ! hash_equals($expected, $offered)) {
            $this->log()->warning('handshake', ['ok' => false]);

            return response('', 403);
        }

        if ($request->query('hub_mode') !== 'subscribe') {
            return response('', 400);
        }

        $this->log()->info('handshake', ['ok' => true]);

        return response((string) $request->query('hub_challenge'), 200);
    }

    /**
     * Meta retries anything it does not see a 200 for, so this answers 200 for
     * everything it could possibly have sent — including payloads we make no
     * sense of. A receipt we cannot use is not a reason to be retried for a
     * day; only a refused signature is worth rejecting.
     */
    public function handle(Request $request): Response
    {
        if (! $this->signed($request)) {
            $this->log()->warning('refused', [
                'reason' => config('services.whatsapp.app_secret') ? 'bad_signature' : 'no_app_secret',
            ]);

            return response('', 403);
        }

        $statuses = 0;
        $matched = 0;
        $states = [];
        $messages = 0;

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $status = is_array($status) ? $status : [];
                    $statuses++;
                    $state = is_string($status['status'] ?? null) ? $status['status'] : 'unknown';
                    $states[$state] = ($states[$state] ?? 0) + 1;

                    if ($this->receipts->record($status) !== null) {
                        $matched++;
                    }
                }

                $messages += count($change['value']['messages'] ?? []);
            }
        }

        // Counts only: whether Meta is calling, and whether what it reports
        // is about messages we sent. No phone numbers, no message text.
        $this->log()->info('callback', compact('statuses', 'matched', 'states', 'messages'));

        return response('', 200);
    }

    private function log(): LoggerInterface
    {
        return Log::channel('whatsapp_webhook');
    }

    /**
     * Meta signs the raw body with the app secret. Compared in constant time,
     * against the body as it arrived rather than as Laravel re-encodes it.
     */
    private function signed(Request $request): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');

        if ($secret === '') {
            return false;
        }

        $offered = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return $offered !== '' && hash_equals($expected, $offered);
    }
}
