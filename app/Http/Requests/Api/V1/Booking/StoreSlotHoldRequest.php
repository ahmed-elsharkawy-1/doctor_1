<?php

namespace App\Http\Requests\Api\V1\Booking;

use Illuminate\Foundation\Http\FormRequest;

class StoreSlotHoldRequest extends FormRequest
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
            'start_time' => ['required', 'date_format:H:i'],
            'visit_type_id' => ['required', 'integer'],
            // The caller's existing hold. Sending it moves that claim onto the
            // new time instead of leaving a second one behind, so a patient
            // browsing the day never locks up more than one slot.
            'token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
