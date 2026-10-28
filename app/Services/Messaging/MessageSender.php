<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Models\OutboundMessage;

interface MessageSender
{
    public function send(OutboundMessage $message): void;

    /**
     * A plain text message inside a conversation the patient started — the
     * follow-up after a reminder button. Not a template, and valid only while
     * WhatsApp's 24-hour session window is open.
     */
    public function sendText(string $to, string $body): ?string;

    /**
     * A template straight to a number — for messages that go to a clinic
     * account rather than a patient, such as the doctor's morning report.
     *
     * @param  array<int, mixed>  $variables  body parameters, in the template's order
     * @return string|null the provider's message id
     */
    public function sendTemplate(string $to, MessageTemplate $template, array $variables, ?string $buttonSuffix): ?string;
}
