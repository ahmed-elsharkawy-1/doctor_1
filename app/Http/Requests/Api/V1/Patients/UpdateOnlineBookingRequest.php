<?php

namespace App\Http\Requests\Api\V1\Patients;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOnlineBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `allowed: true` lifts a block, `allowed: false` blocks by hand.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'allowed' => ['required', 'boolean'],
        ];
    }

    public function allowed(): bool
    {
        return $this->boolean('allowed');
    }
}
