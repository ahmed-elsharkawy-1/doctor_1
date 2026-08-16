<?php

namespace App\Services\Messaging;

use App\Models\OutboundMessage;

interface MessageSender
{
    public function send(OutboundMessage $message): void;
}
