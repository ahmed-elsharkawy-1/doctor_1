<?php

namespace App\Http\Requests\Api\V1\Queue;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingStatusRequest extends FormRequest
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
            'to' => ['required', Rule::in(array_column(BookingStatus::cases(), 'value'))],
        ];
    }

    public function target(): BookingStatus
    {
        return BookingStatus::from((string) $this->validated('to'));
    }
}
