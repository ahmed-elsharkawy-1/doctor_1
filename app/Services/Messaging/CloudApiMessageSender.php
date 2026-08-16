<?php

namespace App\Services\Messaging;

use App\Models\OutboundMessage;
use RuntimeException;

class CloudApiMessageSender implements MessageSender
{
    public function send(OutboundMessage $message): void
    {
        throw new RuntimeException('WhatsApp Cloud API driver is not configured in this build.');
    }
}
