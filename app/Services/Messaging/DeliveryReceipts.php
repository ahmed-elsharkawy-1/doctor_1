<?php

namespace App\Services\Messaging;

use App\Models\OutboundMessage;
use Illuminate\Support\Carbon;

/**
 * What Meta tells us happened to a message after it accepted it.
 *
 * Sending only ever proved that Meta took the message. Whether it reached the
 * patient, was read, or was quietly dropped — for exceeding a marketing
 * frequency cap, say — arrives later on this path and nowhere else.
 */
class DeliveryReceipts
{
    /**
     * Later states never lose to earlier ones. Meta does not promise callbacks
     * in order, and a `delivered` arriving after a `read` must not walk the
     * message backwards.
     *
     * `failed` sits top: whatever came before, a failure is the outcome.
     */
    private const RANK = [
        'queued' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'failed' => 4,
    ];

    /**
     * @param  array<string, mixed>  $status  one entry of `value.statuses`
     */
    public function record(array $status): ?OutboundMessage
    {
        $wamid = $status['id'] ?? null;
        $state = $status['status'] ?? null;

        if (! is_string($wamid) || ! is_string($state) || ! isset(self::RANK[$state])) {
            return null;
        }

        $message = OutboundMessage::where('provider_message_id', $wamid)->first();

        // A receipt for something we never sent, or sent before this table
        // existed. Nothing to record, and nothing wrong.
        if ($message === null) {
            return null;
        }

        if (self::RANK[$state] <= (self::RANK[$message->status] ?? 0)) {
            return $message;
        }

        $message->update($this->attributes($state, $status, $message));

        return $message->refresh();
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function attributes(string $state, array $status, OutboundMessage $message): array
    {
        $at = $this->timestamp($status);

        return match ($state) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => $at],
            // A read message was delivered, whether or not that callback ever
            // arrived — Meta does not promise both, or either order.
            'read' => [
                'status' => 'read',
                'read_at' => $at,
                'delivered_at' => $message->delivered_at ?? $at,
            ],
            'failed' => ['status' => 'failed', 'error' => $this->reason($status)],
            default => ['status' => $state],
        };
    }

    /**
     * Meta sends seconds since the epoch as a string.
     */
    private function timestamp(array $status): Carbon
    {
        $seconds = $status['timestamp'] ?? null;

        return is_numeric($seconds) ? Carbon::createFromTimestamp((int) $seconds) : Carbon::now();
    }

    /**
     * Meta's own words. A dropped marketing message and an invalid number are
     * both "failed" and only the reason tells them apart.
     */
    private function reason(array $status): string
    {
        $error = $status['errors'][0] ?? [];

        return trim(implode(' — ', array_filter([
            $error['code'] ?? null,
            $error['title'] ?? null,
            $error['error_data']['details'] ?? $error['message'] ?? null,
        ]))) ?: 'Delivery failed without a stated reason.';
    }
}
