<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\OutboundMessage;
use App\Services\Messaging\MessageSender;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId) {}

    public function handle(MessageSender $sender): void
    {
        $message = OutboundMessage::find($this->messageId);

        // The row was deleted after the job was queued — a cancelled booking,
        // or a purge. There is nothing to send and nothing wrong, so stop
        // rather than retrying three times and landing in failed_jobs.
        if ($message === null) {
            return;
        }

        // The reminder gets exactly one retry, as a product rule: a second
        // failure is final and must not become a retry loop. Other messages
        // keep the queue's retry behaviour.
        if ($message->template_key === WhatsAppMessagingService::REMINDER_KEY) {
            $this->sendReminder($sender, $message);

            return;
        }

        try {
            $message->update(['attempts' => (int) $message->attempts + 1]);
            $sender->send($message);
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Two provider calls at most. The row's `attempts` makes that true even
     * if the worker itself retries the job, and makes "failed twice" visible
     * on the card instead of only in the queue's failed-jobs table.
     */
    private function sendReminder(MessageSender $sender, OutboundMessage $message): void
    {
        // Cancelled, or moved along the queue, between the command picking it
        // and the worker reaching it. A reminder now would ask a patient to
        // confirm a visit that is no longer waiting for her answer.
        if ($message->booking?->status !== BookingStatus::BOOKED) {
            $message->update(['status' => 'skipped']);

            return;
        }

        $maxAttempts = 2;

        while ((int) $message->attempts < $maxAttempts) {
            $message->update(['attempts' => (int) $message->attempts + 1]);

            try {
                $sender->send($message);

                return;
            } catch (Throwable $exception) {
                $message->update([
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ]);

                if ((int) $message->attempts >= $maxAttempts) {
                    // Final. Reported for the operator, but not rethrown:
                    // throwing would send the queue round again and turn one
                    // retry into the loop this rule exists to prevent.
                    report($exception);

                    return;
                }

                // One more try, from the loop.
            }
        }
    }
}
