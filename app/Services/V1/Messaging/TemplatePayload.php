<?php

namespace App\Services\V1\Messaging;

/**
 * What one WhatsApp template needs filling in for one booking.
 *
 * `body` is positional — Meta matches by order, not by name, so the list is
 * the contract and its length must match the approved template exactly.
 *
 * `buttonSuffix` is the path appended to the template's fixed base URL. Meta
 * registers those buttons as `https://elayadah.com/{{1}}`, so the whole path
 * travels in the parameter: `booking/<token>`, not just the token.
 */
final class TemplatePayload
{
    /**
     * @param  list<string>  $body
     */
    public function __construct(
        public readonly array $body,
        public readonly ?string $buttonSuffix = null,
    ) {}

    public function hasButton(): bool
    {
        return $this->buttonSuffix !== null && $this->buttonSuffix !== '';
    }
}
