<?php

namespace App\Support;

/**
 * Arabic to Latin letters, for patient ID codes (SPEC §5.3).
 *
 * Deliberately consonantal and deterministic rather than phonetic — the code
 * only has to be stable, unique and typeable, not a faithful romanisation.
 * The same name always yields the same letters.
 */
final class ArabicTransliterator
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'ا' => 'A', 'أ' => 'A', 'إ' => 'I', 'آ' => 'A', 'ء' => 'A', 'ى' => 'A', 'ة' => 'A',
        'ب' => 'B',
        'ت' => 'T', 'ث' => 'TH',
        'ج' => 'G', 'ح' => 'H', 'خ' => 'KH',
        'د' => 'D', 'ذ' => 'Z',
        'ر' => 'R', 'ز' => 'Z',
        'س' => 'S', 'ش' => 'SH',
        'ص' => 'S', 'ض' => 'D',
        'ط' => 'T', 'ظ' => 'Z',
        'ع' => 'A', 'غ' => 'GH',
        'ف' => 'F', 'ق' => 'Q',
        'ك' => 'K', 'ل' => 'L', 'م' => 'M', 'ن' => 'N',
        'ه' => 'H', 'و' => 'W', 'ي' => 'Y',
        'ئ' => 'Y', 'ؤ' => 'W',
    ];

    /**
     * Latin letters only — Arabic mapped, ASCII passed through, everything
     * else (diacritics, punctuation, digits) dropped.
     */
    public static function toLatin(string $text): string
    {
        $result = '';

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (isset(self::MAP[$character])) {
                $result .= self::MAP[$character];

                continue;
            }

            if (preg_match('/^[A-Za-z]$/', $character) === 1) {
                $result .= strtoupper($character);
            }
        }

        return $result;
    }
}
