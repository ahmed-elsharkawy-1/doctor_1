<?php

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Builders for the API's `value` + `display` pairs — see SPEC §6.4.
 *
 * Anything the Flutter app renders as text ships pre-formatted, so the client
 * never re-implements Arabic formatting. `value` is machine-readable and
 * stable; `display` follows the request's Accept-Language.
 */
final class Wire
{
    /**
     * @return array{value: string, display: string}
     */
    public static function date(DateTimeInterface|string $date): array
    {
        $date = Carbon::parse($date);

        return [
            'value' => $date->format(config('clinic.formats.date')),
            'display' => $date->locale(app()->getLocale())->isoFormat('D MMMM YYYY'),
        ];
    }

    /**
     * @return array{value: string, display: string}
     */
    public static function time(DateTimeInterface|string $time): array
    {
        $time = $time instanceof DateTimeInterface ? Carbon::instance($time) : Carbon::parse($time);

        return [
            'value' => $time->format(config('clinic.formats.time')),
            'display' => self::displayTime($time),
        ];
    }

    /**
     * @return array{value: string, display: string}
     */
    public static function dateTime(DateTimeInterface|string $dateTime): array
    {
        $dateTime = Carbon::parse($dateTime);

        return [
            'value' => $dateTime->format(config('clinic.formats.datetime')),
            'display' => self::displayTime($dateTime),
        ];
    }

    /**
     * @return array{value: string, display: string}
     */
    public static function enum(BackedEnum $enum, string $display): array
    {
        return [
            'value' => (string) $enum->value,
            'display' => $display,
        ];
    }

    /**
     * @return array{value: string, display: string}
     */
    public static function money(int|float|string $amount): array
    {
        $decimals = config('clinic.formats.money_decimals');
        $formatted = number_format((float) $amount, $decimals, '.', '');

        return [
            'value' => $formatted,
            'display' => number_format((float) $amount, $decimals).' '.__('messages.currency'),
        ];
    }

    /**
     * Full number — only where a call action exists.
     *
     * @return array{value: string, display: string}
     */
    public static function phone(?PhoneNumber $phone): ?array
    {
        if ($phone === null) {
            return null;
        }

        return [
            'value' => $phone->e164,
            'display' => $phone->national(),
        ];
    }

    /**
     * Partially masked — for search results and list views (SPEC §4.4).
     *
     * @return array{value: string, display: string}
     */
    public static function maskedPhone(?PhoneNumber $phone): ?array
    {
        if ($phone === null) {
            return null;
        }

        return [
            'value' => $phone->e164,
            'display' => $phone->masked(),
        ];
    }

    /**
     * Arabic renders 9:20 ص / 2:00 م; English renders 9:20 AM.
     */
    private static function displayTime(Carbon $time): string
    {
        if (app()->getLocale() !== 'ar') {
            return $time->format('g:i A');
        }

        $meridiem = $time->hour < 12 ? __('messages.am') : __('messages.pm');

        return $time->format('g:i').' '.$meridiem;
    }
}
