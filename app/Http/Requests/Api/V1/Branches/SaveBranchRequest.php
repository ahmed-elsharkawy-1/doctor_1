<?php

namespace App\Http\Requests\Api\V1\Branches;

use App\DTOs\V1\Branches\BranchData;
use App\Models\Clinic;
use App\Support\MapLink;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or editing a branch (ELA-16). The pin comes either as a pasted
 * Google Maps link or as latitude and longitude; the link wins when both
 * are sent.
 */
class SaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return self::rulesFor($this->clinic()->country_code);
    }

    /**
     * Shared with the web screen, so both say the same thing about the same
     * input.
     *
     * @return array<string, list<mixed>>
     */
    public static function rulesFor(?string $countryCode): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'map_link' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && MapLink::coordinates($value) === null) {
                    $fail(__('settings.branch.map_link_unreadable'));
                }
            }],
            'latitude' => ['required_without:map_link', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:map_link', 'nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) use ($countryCode): void {
                if (is_string($value) && trim($value) !== '' && PhoneNumber::tryParse($value, $countryCode) === null) {
                    $fail(__('settings.branch.phone_invalid'));
                }
            }],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  already validated against rulesFor()
     */
    public static function toData(array $input, ?string $countryCode): BranchData
    {
        $pin = filled($input['map_link'] ?? null) ? MapLink::coordinates($input['map_link']) : null;
        $phone = filled($input['phone'] ?? null) ? PhoneNumber::tryParse($input['phone'], $countryCode)?->e164 : null;

        return new BranchData(
            name: trim($input['name']),
            address: trim($input['address']),
            latitude: $pin[0] ?? (float) $input['latitude'],
            longitude: $pin[1] ?? (float) $input['longitude'],
            phone: $phone,
        );
    }

    public function branchData(): BranchData
    {
        return self::toData($this->validated(), $this->clinic()->country_code);
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->attributes->get('clinic');

        return $clinic;
    }
}
