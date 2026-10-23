<?php

namespace App\Support;

/**
 * A delay in words: "نصف ساعة", "ساعة ونصف", "ساعتين و10 دقائق".
 *
 * Shown in the app's list of delays and against each patient a delay moved.
 * The cascade can give a patient any whole number of minutes — a gap absorbs
 * part of the delay — so it is built from the parts rather than looked up.
 */
final class DelayLabel
{
    public static function for(int $minutes, ?string $locale = null): string
    {
        $minutes = max(1, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest === 30
                ? __('booking.delay.half_hour', [], $locale)
                : trans_choice('booking.delay.minutes', $rest, [], $locale);
        }

        $hoursText = trans_choice('booking.delay.hours', $hours, [], $locale);

        return match (true) {
            $rest === 0 => $hoursText,
            $rest === 30 => __('booking.delay.hours_and_half', ['hours' => $hoursText], $locale),
            default => __('booking.delay.hours_and_minutes', [
                'hours' => $hoursText,
                'minutes' => trans_choice('booking.delay.minutes', $rest, [], $locale),
            ], $locale),
        };
    }
}
