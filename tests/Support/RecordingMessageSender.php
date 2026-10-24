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

    /** @var list<array{to: string, body: string}> */
    public array $texts = [];

    public ?string $failWith = null;

    /** How many of the next sends fail before one succeeds. */
    public int $failFirst = 0;

    /** Every provider call, successful or not. */
    public int $sendAttempts = 0;

    public function send(OutboundMessage $message): void
    {
        $this->sendAttempts++;

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        if ($this->failFirst > 0) {
            $this->failFirst--;

            throw new RuntimeException('First send failed.');
        }

        if ($message->type === 'text') {
            $this->texts[] = ['to' => (string) $message->patient?->phone, 'body' => (string) $message->rendered_body];
        }

        $message->update([
            'status' => 'sent',
            'provider_message_id' => 'rec_'.$message->id,
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    public function sendText(string $to, string $body): ?string
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->texts[] = ['to' => $to, 'body' => $body];

        return 'rec_text_'.count($this->texts);
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
