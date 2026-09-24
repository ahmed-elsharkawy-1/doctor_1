<?php

namespace App\Http\Requests\Api\V1\Booking;

use Illuminate\Foundation\Http\FormRequest;

class SlotAvailabilityRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'visit_type_id' => ['required', 'integer'],
            // The caller's own slot hold, so the grid does not grey out the
            // time this very screen is sitting on.
            'hold_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
