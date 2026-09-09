<?php

namespace App\Enums;

/**
 * Recorded for one reason: choosing which stock avatar stands in for a doctor
 * who has not uploaded a photo. Nothing clinical depends on it.
 */
enum DoctorSex: string
{
    case MALE = 'male';
    case FEMALE = 'female';

    public function label(): string
    {
        return __('user.sex.'.$this->value);
    }

    /**
     * The seeded avatar shown until a real portrait is uploaded.
     */
    public function avatarUrl(): string
    {
        return asset(
            config('clinic.public.avatar_path')
            .'/'.$this->value
            .'.'.config('clinic.public.avatar_extension'),
        );
    }

    /**
     * @return array<string, string> value => label, for Filament selects
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
