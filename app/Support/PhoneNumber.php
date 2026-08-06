<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Phone normalisation — see SPEC §5.2.
 *
 * Stored E.164 (+201012345678), displayed nationally (01012345678). Country
 * rules come from config/clinic.php; nothing about a country is hardcoded here.
 */
final class PhoneNumber
{
    private function __construct(
        public readonly string $e164,
        public readonly string $countryCode,
    ) {}

    /**
     * @throws InvalidArgumentException when the input cannot be normalised
     */
    public static function parse(string $input, ?string $countryCode = null): self
    {
        $countryCode = strtoupper($countryCode ?? config('clinic.phone.default_country'));
        $country = config('clinic.phone.countries.'.$countryCode);

        if ($country === null) {
            throw new InvalidArgumentException("Unknown country code [{$countryCode}].");
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Phone number contains no digits.');
        }

        $national = self::toNational($digits, $country);

        if (strlen($national) !== $country['national_length']) {
            throw new InvalidArgumentException('Phone number has the wrong length.');
        }

        return new self('+'.$country['dial_code'].$national, $countryCode);
    }

    public static function tryParse(string $input, ?string $countryCode = null): ?self
    {
        try {
            return self::parse($input, $countryCode);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Strips the international prefix, dial code and/or trunk prefix to get
     * the bare national number.
     *
     * @param  array{dial_code: string, trunk_prefix: string, national_length: int}  $country
     */
    private static function toNational(string $digits, array $country): string
    {
        // 00 20 101... — the dialled international prefix.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $country['dial_code'])) {
            $digits = substr($digits, strlen($country['dial_code']));
        }

        $trunk = $country['trunk_prefix'];

        if ($trunk !== '' && str_starts_with($digits, $trunk)) {
            $digits = substr($digits, strlen($trunk));
        }

        return $digits;
    }

    /**
     * National form, as the secretary would write it: 01012345678.
     */
    public function national(): string
    {
        $country = config('clinic.phone.countries.'.$this->countryCode);
        $withoutDialCode = substr($this->e164, strlen($country['dial_code']) + 1);

        return $country['trunk_prefix'].$withoutDialCode;
    }

    /**
     * Partially masked for search and list views — 0101***5678 (SPEC §4.4).
     * Full numbers are only shown where a call action exists.
     */
    public function masked(): string
    {
        $config = config('clinic.phone.mask');
        $national = $this->national();

        $prefix = substr($national, 0, $config['visible_prefix']);
        $suffix = substr($national, -$config['visible_suffix']);

        return $prefix.str_repeat($config['mask_character'], $config['masked_length']).$suffix;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
