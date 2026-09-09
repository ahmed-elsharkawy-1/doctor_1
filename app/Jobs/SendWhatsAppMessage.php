<?php

namespace App\Jobs;

use App\Models\OutboundMessage;
use App\Services\Messaging\MessageSender;
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

        try {
            $sender->send($message);
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
