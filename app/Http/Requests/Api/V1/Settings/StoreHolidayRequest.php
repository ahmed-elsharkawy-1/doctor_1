<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
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
            // A range (ELA-20): the last day off, inclusive. Absent: one day.
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            // Absent or null: every branch, including ones added later.
            'branch_ids' => ['nullable', 'array', 'min:1'],
            'branch_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
            // Confirms closing a day that already has patients booked.
            'force' => ['nullable', 'boolean'],
        ];
    }
}
