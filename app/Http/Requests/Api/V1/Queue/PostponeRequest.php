<?php

namespace App\Http\Requests\Api\V1\Queue;

use Illuminate\Foundation\Http\FormRequest;

class PostponeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Omit `booking_ids` for "كل المريضات"; send them for "مريضات محددة".
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'booking_ids' => ['nullable', 'array'],
            'booking_ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>|null
     */
    public function bookingIds(): ?array
    {
        $ids = $this->validated('booking_ids');

        return $ids === null ? null : array_map('intval', $ids);
    }
}
