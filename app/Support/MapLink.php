<?php

namespace App\Support;

/**
 * Reads a map pin out of a pasted Google Maps link (ELA-16: "location via map
 * or pasted Google Maps link").
 *
 * Only full links carry the coordinates. A short `maps.app.goo.gl` link would
 * have to be followed to the real address, and the app makes no outbound
 * request for that; the doctor is asked for the full link or the pin instead.
 */
final class MapLink
{
    /**
     * The patterns, most precise first: `!3d…!4d…` is the place itself,
     * `@lat,lng` only where the map was centred.
     */
    private const PATTERNS = [
        '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
        '/[?&](?:q|query|ll|destination|center)=(-?\d{1,2}(?:\.\d+)?)(?:,|%2C)\s*(-?\d{1,3}(?:\.\d+)?)/i',
        '/\/(?:search|place|dir)\/(-?\d{1,2}(?:\.\d+)?),\s*\+?(-?\d{1,3}(?:\.\d+)?)/',
        '/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/',
    ];

    /** @return array{0: float, 1: float}|null */
    public static function coordinates(string $link): ?array
    {
        $link = trim($link);

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $link, $m) === 1) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [round($lat, 7), round($lng, 7)];
                }
            }
        }

        return null;
    }
}
