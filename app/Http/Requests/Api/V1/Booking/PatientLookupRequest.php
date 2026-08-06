<?php

namespace App\Http\Requests\Api\V1\Booking;

use Illuminate\Foundation\Http\FormRequest;

class PatientLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `name` and `visit_type_id` are optional: the screen calls this as soon
     * as the phone is complete, before the rest is filled in.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'visit_type_id' => ['nullable', 'integer'],
        ];
    }
}
