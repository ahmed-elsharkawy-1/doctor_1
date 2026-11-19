<?php

namespace App\Http\Requests\Api\V1\Patients;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `enabled: false` stops every WhatsApp message to the patient,
     * `enabled: true` turns them back on.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
