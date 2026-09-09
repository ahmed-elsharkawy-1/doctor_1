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
        public readonly ?string $description = null,
        // Whether the caller sent the key at all. A client that knows nothing
        // about descriptions must not erase one by omitting it.
        public readonly bool $descriptionProvided = false,
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
            // The line under each service on the public page.
            description: array_key_exists('description', $validated)
                ? (trim((string) $validated['description']) ?: null)
                : null,
            descriptionProvided: array_key_exists('description', $validated),
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

        // Sending an empty string clears it; omitting the key leaves it alone.
        if ($this->descriptionProvided) {
            $attributes['description'] = $this->description;
        }

        if ($this->price !== null) {
            $attributes['price'] = $this->price;
        }

        if ($this->isNewPatientType !== null) {
            $attributes['is_new_patient_type'] = $this->isNewPatientType;
        }

        return $attributes;
    }
}
