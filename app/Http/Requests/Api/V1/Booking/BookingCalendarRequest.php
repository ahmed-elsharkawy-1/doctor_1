<?php

namespace App\Http\Requests\Api\V1\Booking;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingCalendarRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(array_column(BookingStatus::cases(), 'value'))],
        ];
    }

    public function status(): ?BookingStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : BookingStatus::from((string) $status);
    }
}
