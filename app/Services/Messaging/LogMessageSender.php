<?php

namespace App\Services\Messaging;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogMessageSender implements MessageSender
{
    public function send(OutboundMessage $message): void
    {
        // The recipient and the body are the whole point of this driver:
        // until Meta approves the templates, the log is the only way to see
        // that the right patient would get the right text.
        Log::info('WhatsApp log driver message rendered.', [
            'outbound_message_id' => $message->id,
            'template_key' => $message->template_key,
            'patient_id' => $message->patient_id,
            'booking_id' => $message->booking_id,
            'to' => $message->patient?->phone,
            'body' => $message->rendered_body,
        ]);

        $message->update([
            'status' => 'sent',
            'provider_message_id' => 'log_'.Str::uuid(),
            'sent_at' => now(),
            'error' => null,
        ]);
    }
}
