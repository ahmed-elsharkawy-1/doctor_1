<?php

namespace Tests\Support;

use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Services\Messaging\MessageSender;
use RuntimeException;

/**
 * Captures WhatsApp templates instead of sending them, or fails on demand.
 *
 * Bind it with `$this->app->instance(MessageSender::class, $sender)`.
 */
class RecordingMessageSender implements MessageSender
{
    /** @var list<array{to: string, template: string, variables: array, button: ?string}> */
    public array $templates = [];

    public ?string $failWith = null;

    public function send(OutboundMessage $message): void
    {
        $message->update(['status' => 'sent', 'provider_message_id' => 'rec_'.$message->id, 'sent_at' => now()]);
    }

    public function sendTemplate(string $to, MessageTemplate $template, array $variables, ?string $buttonSuffix): ?string
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->templates[] = ['to' => $to, 'template' => $template->key, 'variables' => $variables, 'button' => $buttonSuffix];

        return 'rec_'.count($this->templates);
    }
}
