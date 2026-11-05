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
     * The patient is `patient_id`, or `patient_name` and `phone` — found by
     * phone if the clinic knows them, created if not, as a booking does.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required_without:patient_id', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:patient_id', 'nullable', 'string', 'max:32'],
            'visit_type_id' => ['nullable', 'integer'],
        ];
    }
}
