<?php

namespace App\Http\Requests\Api\V1\Booking;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
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
            'patient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'visit_type_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Overbooking override — books past a full day or outside hours.
            'force' => ['nullable', 'boolean'],
            // Confirms replacing the stored name when the phone is known.
            'update_patient_name' => ['nullable', 'boolean'],
        ];
    }
}
