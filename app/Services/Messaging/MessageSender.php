<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Models\OutboundMessage;

interface MessageSender
{
    public function send(OutboundMessage $message): void;

    /**
     * A template straight to a number — for messages that go to a clinic
     * account rather than a patient, such as the doctor's morning report.
     *
     * @param  array<int, mixed>  $variables  body parameters, in the template's order
     * @return string|null the provider's message id
     */
    public function sendTemplate(string $to, MessageTemplate $template, array $variables, ?string $buttonSuffix): ?string;
}
