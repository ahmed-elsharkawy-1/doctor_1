<?php

namespace App\DTOs\V1\Settings;

/**
 * One open period within a day, as `HH:MM`.
 */
final class SchedulePeriodData
{
    public function __construct(
        public readonly string $startTime,
        public readonly string $endTime,
    ) {}

    /**
     * @param  array<string, mixed>  $period
     */
    public static function fromArray(array $period): self
    {
        return new self(
            startTime: self::normalise((string) $period['start_time']),
            endTime: self::normalise((string) $period['end_time']),
        );
    }

    /**
     * An end at or before the start means the period runs past midnight —
     * 19:00 to 03:00 is one evening, not an error. Only an empty period, or
     * an overnight one that runs on past `latest_overnight_close`, is refused:
     * the cap is what stops 09:00 to 05:00, a typo, becoming a twenty-hour day.
     */
    public function isValid(): bool
    {
        if ($this->startTime === $this->endTime) {
            return false;
        }

        return ! $this->crossesMidnight()
            || $this->endTime <= config('clinic.schedule.latest_overnight_close');
    }

    public function crossesMidnight(): bool
    {
        return $this->endTime < $this->startTime;
    }

    /**
     * Compared as minutes from the start of the day, an overnight end counted
     * on into the next one — a string comparison would read 19:00–03:00 as
     * ending before it starts.
     */
    public function overlaps(self $other): bool
    {
        return $this->startMinute() < $other->endMinute() && $this->endMinute() > $other->startMinute();
    }

    public function startMinute(): int
    {
        return self::minutes($this->startTime);
    }

    public function endMinute(): int
    {
        return self::minutes($this->endTime) + ($this->crossesMidnight() ? 24 * 60 : 0);
    }

    /**
     * @return array<string, string>
     */
    public function toAttributes(): array
    {
        return [
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
        ];
    }

    /**
     * Accepts `HH:MM` or `HH:MM:SS`; string comparison is only safe on a
     * fixed-width value.
     */
    private static function normalise(string $time): string
    {
        return substr($time, 0, 5);
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
