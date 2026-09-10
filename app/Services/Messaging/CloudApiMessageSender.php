<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Delivery through Meta's WhatsApp Cloud API.
 *
 * Meta renders the approved template itself; we send only its name, language
 * and parameters. `rendered_body` on the message stays our own copy for the
 * dashboard and for support — it is never what travels.
 *
 * Parameters are positional and the template's approved shape is the contract:
 * a wrong count is rejected whole, so the resolver that built them, not this
 * class, is where a mismatch belongs. Here we only post what it produced.
 */
class CloudApiMessageSender implements MessageSender
{
    public function send(OutboundMessage $message): void
    {
        $token = (string) config('services.whatsapp.token');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

        if ($token === '' || $phoneNumberId === '') {
            throw new RuntimeException(
                'WhatsApp Cloud API is selected but WHATSAPP_SYSTEM_USER_TOKEN '
                .'or WHATSAPP_PHONE_NUMBER_ID is not set.',
            );
        }

        $to = $this->recipient($message);
        $template = $this->template($message);

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.whatsapp.timeout', 15))
            ->post($this->endpoint($phoneNumberId), [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => array_filter([
                    'name' => $template->provider_template_name ?: $template->key,
                    'language' => ['code' => $template->language_code ?: 'ar'],
                    'components' => $this->components($message),
                ]),
            ]);

        if ($response->failed()) {
            // Meta's own words are far more useful than a status code — an
            // unapproved template and a malformed parameter both come back
            // as 400, and only the message tells them apart.
            $error = $response->json('error.message')
                ?? 'WhatsApp Cloud API returned HTTP '.$response->status().'.';

            throw new RuntimeException($error);
        }

        $message->update([
            'status' => 'sent',
            'provider_message_id' => $response->json('messages.0.id'),
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * Meta wants the number in international form with no punctuation and no
     * leading plus. Patients are stored E.164, so this is the last step.
     */
    private function recipient(OutboundMessage $message): string
    {
        $phone = (string) $message->patient?->phone;
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if ($phone === '') {
            throw new RuntimeException(
                "Outbound message [{$message->id}] has no phone number to send to.",
            );
        }

        return $phone;
    }

    private function template(OutboundMessage $message): MessageTemplate
    {
        $template = MessageTemplate::where('key', $message->template_key)->first();

        if ($template === null) {
            throw new RuntimeException(
                "Message template [{$message->template_key}] no longer exists.",
            );
        }

        return $template;
    }

    /**
     * Body parameters and the dynamic URL button, in the shape Meta expects.
     *
     * A template with neither — which none of the three approved ones is —
     * would send no components at all, so the array is filtered rather than
     * assumed non-empty.
     *
     * @return list<array<string, mixed>>
     */
    private function components(OutboundMessage $message): array
    {
        $components = [];
        $variables = $message->variables ?? [];

        if ($variables !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn ($value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($variables),
                ),
            ];
        }

        if (($message->button_suffix ?? '') !== '') {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                // The templates each carry exactly one button.
                'index' => '0',
                'parameters' => [
                    ['type' => 'text', 'text' => (string) $message->button_suffix],
                ],
            ];
        }

        return $components;
    }

    private function endpoint(string $phoneNumberId): string
    {
        $version = (string) config('services.whatsapp.api_version', 'v25.0');

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    }
}
