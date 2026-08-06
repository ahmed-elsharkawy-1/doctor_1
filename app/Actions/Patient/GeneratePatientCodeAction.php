<?php

namespace App\Actions\Patient;

use App\Models\Clinic;
use App\Support\ArabicTransliterator;
use App\Support\PhoneNumber;
use RuntimeException;

/**
 * Builds a patient's ID code — SPEC §5.3.
 *
 * Letters from the first words of the name, then the last digits of the phone:
 * "سارة أحمد" + 01012225521 -> SAAH5521
 *
 * The code is assigned once and never changes; correcting a name or phone
 * later does not regenerate it, because the code is the search key.
 */
class GeneratePatientCodeAction
{
    public function execute(Clinic $clinic, string $name, PhoneNumber $phone): string
    {
        $config = config('clinic.patient_code');

        $base = $this->letters($name, $config).$this->digits($phone, $config);

        return $this->makeUnique($clinic, $base, $config['max_collision_attempts']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function letters(string $name, array $config): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $perWord = $config['letters_per_word'];
        $letters = '';

        foreach (array_slice($words, 0, $config['words']) as $word) {
            $latin = ArabicTransliterator::toLatin($word);

            if ($latin === '') {
                continue;
            }

            $letters .= str_pad(
                substr($latin, 0, $perWord),
                $perWord,
                $config['padding_letter'],
            );
        }

        // A name that transliterates to nothing at all still needs a code.
        if ($letters === '') {
            $letters = $config['fallback_letters'];
        }

        $width = $config['words'] * $perWord;

        return str_pad(substr($letters, 0, $width), $width, $config['padding_letter']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function digits(PhoneNumber $phone, array $config): string
    {
        $national = $phone->national();

        return substr($national, -$config['phone_digits']);
    }

    /**
     * Two patients can legitimately produce the same base — same-ish name,
     * same last four digits. A numeric suffix keeps the code unique per clinic.
     */
    private function makeUnique(Clinic $clinic, string $base, int $maxAttempts): string
    {
        if (! $this->exists($clinic, $base)) {
            return $base;
        }

        for ($suffix = 1; $suffix <= $maxAttempts; $suffix++) {
            $candidate = $base.$suffix;

            if (! $this->exists($clinic, $candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Could not generate a unique patient code from [{$base}].");
    }

    private function exists(Clinic $clinic, string $code): bool
    {
        return $clinic->patients()->where('code', $code)->exists();
    }
}
