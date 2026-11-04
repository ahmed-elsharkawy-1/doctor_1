<?php

namespace App\Http\Requests\Api\V1\WaitingList;

use Illuminate\Foundation\Http\FormRequest;

class ListWaitingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `date` defaults to the clinic's current day.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
