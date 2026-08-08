<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Both fields are optional so the app can save one without echoing the
     * other back.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'booking_window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'first_visit_only_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
            'patient_arrival_lead_minutes' => [
                'sometimes',
                'integer',
                Rule::in(config('clinic.settings.patient_arrival_lead_minute_options')),
            ],
        ];
    }
}
