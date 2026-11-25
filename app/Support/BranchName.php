<?php

namespace App\Support;

/**
 * The name a clinic's first branch starts with, before the doctor renames it.
 *
 * Patients read it on every WhatsApp message (`🏥 العيادة: …`), so it names
 * the area the clinic is in: "عيادة الدقي" from "١٢ شارع مصدق، الدقي، الجيزة".
 * An address with no area in it falls back to the clinic's own name.
 */
final class BranchName
{
    private const PREFIX = 'عيادة ';

    public static function forFirstBranch(?string $address, ?string $city, string $clinicName): string
    {
        $area = self::area($address, $city);

        return $area === null ? $clinicName : self::PREFIX.$area;
    }

    /**
     * The address's last part that is neither its first (the street) nor the
     * city or governorate, which is too broad to tell two branches apart.
     */
    private static function area(?string $address, ?string $city): ?string
    {
        if (blank($address)) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map('trim', preg_split('/[،,]/u', $address) ?: []),
            fn (string $part): bool => $part !== '',
        ));

        $city = blank($city) ? null : trim($city);

        for ($i = count($parts) - 1; $i >= 1; $i--) {
            if ($parts[$i] !== $city && ! preg_match('/^[\d٠-٩]/u', $parts[$i])) {
                return mb_substr($parts[$i], 0, 80);
            }
        }

        return null;
    }
}
