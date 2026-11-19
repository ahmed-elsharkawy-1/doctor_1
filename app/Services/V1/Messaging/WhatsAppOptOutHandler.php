<?php

namespace App\Services\V1\Messaging;

use App\Services\V1\Patients\WhatsAppConsentService;
use Throwable;

/**
 * A patient replying «إيقاف» (or "stop") on WhatsApp stops our messages.
 *
 * WhatsApp expects a business to honour that, and the privacy policy
 * promises it. Only the bare word counts, after trimming: «إيقاف الحجز» or a
 * sentence that happens to contain "stop" is somebody talking, not asking
 * to unsubscribe.
 */
class WhatsAppOptOutHandler
{
    /** Compared after normalise(). */
    private const KEYWORDS = [
        'ايقاف',
        'ايقاف الرسائل',
        'الغاء الاشتراك',
        'stop',
        'unsubscribe',
    ];

    public function __construct(
        private readonly WhatsAppConsentService $consent,
        private readonly WhatsAppMessagingService $messaging,
    ) {}

    /**
     * Whether this message was a stop request (handled or not).
     *
     * @param  array<string, mixed>  $message  one entry of `value.messages`
     */
    public function handle(array $message): bool
    {
        if (($message['type'] ?? null) !== 'text') {
            return false;
        }

        $body = $message['text']['body'] ?? null;

        if (! is_string($body) || ! in_array($this->normalise($body), self::KEYWORDS, true)) {
            return false;
        }

        $stopped = $this->consent->stopNumber((string) ($message['from'] ?? ''));

        // Told once: a number we do not know, or one already stopped, gets
        // no reply.
        if ($stopped->isNotEmpty()) {
            try {
                $this->messaging->sendOptOutConfirmation($stopped->first());
            } catch (Throwable $e) {
                report($e);
            }
        }

        return true;
    }

    /**
     * Lower case, one space, no punctuation or tatweel, and the hamza forms
     * of alef read as one — «إيقاف», «ايقاف» and «إيقاف.» are the same word.
     */
    private function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['إ', 'أ', 'آ', 'ـ'], ['ا', 'ا', 'ا', ''], $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
