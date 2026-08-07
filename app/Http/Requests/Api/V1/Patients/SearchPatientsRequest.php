<?php

namespace App\Http\Requests\Api\V1\Patients;

use Illuminate\Foundation\Http\FormRequest;

class SearchPatientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `q` is optional so an empty search screen can show the full list.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('clinic.api.pagination.max_per_page')],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('clinic.api.pagination.per_page'));
    }
}
