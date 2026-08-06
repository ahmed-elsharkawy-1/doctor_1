<?php

namespace App\DTOs\V1\Settings;

/**
 * A visit type as submitted by the app.
 *
 * `price` is null when the caller may not set prices — a secretary can add or
 * rename a visit type, but only an owner puts a number on it (SPEC §4.6).
 */
final class VisitTypeData
{
    public function __construct(
        public readonly string $name,
        public readonly int $durationMinutes,
        public readonly ?string $price = null,
        public readonly ?bool $isNewPatientType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, bool $canSetPrice): self
    {
        return new self(
            name: trim((string) $validated['name']),
            durationMinutes: (int) $validated['duration_minutes'],
            price: $canSetPrice && isset($validated['price'])
                ? (string) $validated['price']
                : null,
            isNewPatientType: isset($validated['is_new_patient_type'])
                ? (bool) $validated['is_new_patient_type']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = [
            'name' => $this->name,
            'duration_minutes' => $this->durationMinutes,
        ];

        if ($this->price !== null) {
            $attributes['price'] = $this->price;
        }

        if ($this->isNewPatientType !== null) {
            $attributes['is_new_patient_type'] = $this->isNewPatientType;
        }

        return $attributes;
    }
}
