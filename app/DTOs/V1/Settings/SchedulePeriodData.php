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

    public function startsBeforeItEnds(): bool
    {
        return $this->startTime < $this->endTime;
    }

    public function overlaps(self $other): bool
    {
        return $this->startTime < $other->endTime && $this->endTime > $other->startTime;
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
}
