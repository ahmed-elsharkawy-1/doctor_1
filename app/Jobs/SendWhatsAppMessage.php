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
        $message = OutboundMessage::findOrFail($this->messageId);

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
