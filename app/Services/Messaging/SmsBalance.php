<?php

namespace App\Services\Messaging;

use Illuminate\Support\Carbon;

/**
 * What ZADX says is left on the SMS account, and whether the operator should
 * hear about it.
 *
 * SMS carries the patients' one-time codes, so an empty account quietly stops
 * self-booking. The alert exists to make that visible before it happens.
 */
final readonly class SmsBalance
{
    public const ALERT_NO_PLAN = 'no_plan';

    public const ALERT_EMPTY = 'empty';

    public const ALERT_LOW = 'low';

    public const ALERT_EXPIRING = 'expiring';

    public function __construct(
        public bool $hasActivePlan,
        public ?string $plan,
        public int $remaining,
        public int $quota,
        public ?Carbon $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $body  the JSON of GET /sms/balance
     */
    public static function fromResponse(array $body): self
    {
        $expiresAt = $body['expires_at'] ?? null;

        return new self(
            hasActivePlan: (bool) ($body['has_active_plan'] ?? false),
            plan: is_string($body['plan'] ?? null) ? $body['plan'] : null,
            remaining: (int) ($body['remaining_credits'] ?? 0),
            quota: (int) ($body['quota'] ?? 0),
            expiresAt: is_string($expiresAt) && $expiresAt !== '' ? Carbon::parse($expiresAt) : null,
        );
    }

    /**
     * Whole days until the plan expires; 0 on the last day, negative once
     * it has passed. Null when ZADX gives no date.
     */
    public function daysLeft(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) floor(Carbon::now()->diffInDays($this->expiresAt, false));
    }

    /**
     * The most urgent thing wrong, or null when all is well. One alert at a
     * time: an empty account matters more than a date next week.
     */
    public function alert(): ?string
    {
        $days = $this->daysLeft();

        return match (true) {
            ! $this->hasActivePlan || ($days !== null && $days < 0) => self::ALERT_NO_PLAN,
            $this->remaining <= 0 => self::ALERT_EMPTY,
            $this->remaining <= (int) config('services.zadx.balance.low_credits') => self::ALERT_LOW,
            $days !== null && $days <= (int) config('services.zadx.balance.expiry_days') => self::ALERT_EXPIRING,
            default => null,
        };
    }

    /** The banner's sentence for the current alert. */
    public function alertMessage(): ?string
    {
        $alert = $this->alert();

        return $alert === null ? null : __('filament.sms_balance.alert.'.$alert, [
            'remaining' => number_format($this->remaining),
            'quota' => number_format($this->quota),
            'date' => $this->expiresOn(),
            'days' => $this->daysLeft(),
        ]);
    }

    /** The expiry date on the operator's clock, as Y-m-d. */
    public function expiresOn(): ?string
    {
        return $this->expiresAt?->copy()->timezone(config('app.timezone'))->format('Y-m-d');
    }

    /** Danger for anything that stops codes going out, warning for what soon will. */
    public function alertColor(): ?string
    {
        return match ($this->alert()) {
            self::ALERT_NO_PLAN, self::ALERT_EMPTY => 'danger',
            self::ALERT_LOW, self::ALERT_EXPIRING => 'warning',
            default => null,
        };
    }
}
