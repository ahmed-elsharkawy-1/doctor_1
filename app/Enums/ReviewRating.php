<?php

namespace App\Enums;

/**
 * What a patient thought of the visit — see SPEC v1.3.
 *
 * Three values rather than five stars: it fits a phone, and a short list gets
 * answered far more often than a scale.
 */
enum ReviewRating: string
{
    case VERY_GOOD = 'very_good';
    case GOOD = 'good';
    case BAD = 'bad';

    public function label(): string
    {
        return __('review.rating.'.$this->value);
    }

    /**
     * Best first, the order the page offers them in.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::VERY_GOOD, self::GOOD, self::BAD];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> value => label, for Filament
     */
    public static function options(): array
    {
        return array_reduce(
            self::ordered(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
