<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreVisitTypeRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:100'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            // Accepted only from callers with prices.view; ignored otherwise.
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'is_new_patient_type' => ['nullable', 'boolean'],
        ];
    }

    public function canSetPrice(): bool
    {
        return $this->user()?->hasAbility('prices.view') ?? false;
    }
}
