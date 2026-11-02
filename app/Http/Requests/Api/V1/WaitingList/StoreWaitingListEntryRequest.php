<?php

namespace App\Http\Requests\Api\V1\WaitingList;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaitingListEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An existing patient by `patient_id`, or a caller by name and phone —
     * the same choice as a booking.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'visit_type_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required_without:patient_id', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:patient_id', 'nullable', 'string', 'max:32'],
        ];
    }
}
