<?php

namespace App\Services\Messaging;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogMessageSender implements MessageSender
{
    public function send(OutboundMessage $message): void
    {
        Log::info('WhatsApp log driver message rendered.', [
            'outbound_message_id' => $message->id,
            'template_key' => $message->template_key,
            'patient_id' => $message->patient_id,
            'booking_id' => $message->booking_id,
        ]);

        $message->update([
            'status' => 'sent',
            'provider_message_id' => 'log_'.Str::uuid(),
            'sent_at' => now(),
            'error' => null,
        ]);
    }
}
