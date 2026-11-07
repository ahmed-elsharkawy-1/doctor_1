<?php

namespace App\Services\V1\Messaging;

/**
 * What one WhatsApp template needs filling in for one booking.
 *
 * `body` is positional — Meta matches by order, not by name, so the list is
 * the contract and its length must match the approved template exactly.
 *
 * `buttons` are the paths appended to the template's dynamic URL buttons, in
 * button order: the first is button 0, the second button 1. Meta registers
 * those buttons as `https://elayadah.com/{{1}}`, so the whole path travels in
 * the parameter: `booking/<token>`, not just the token. Quick-reply buttons
 * take no parameter and are not listed.
 */
final class TemplatePayload
{
    /**
     * @param  list<string>  $body
     * @param  list<string>  $buttons
     */
    public function __construct(
        public readonly array $body,
        public readonly array $buttons = [],
    ) {}

    /**
     * The first URL button's path — what the message row keeps in
     * `button_suffix`, and all a one-button template has.
     */
    public function buttonSuffix(): ?string
    {
        return $this->buttons[0] ?? null;
    }

    /**
     * Every URL button's path, for the row's `button_suffixes`. Null for a
     * template with one button or none, which `button_suffix` already covers.
     *
     * @return list<string>|null
     */
    public function extraButtons(): ?array
    {
        return count($this->buttons) > 1 ? $this->buttons : null;
    }
}
