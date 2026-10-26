<?php

namespace App\Http\Requests\Api\V1\Reviews;

use App\Enums\ReviewRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `rating` is the filter chip; leave it out for "الكل".
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['nullable', Rule::enum(ReviewRating::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('clinic.api.pagination.max_per_page')],
        ];
    }

    public function rating(): ?ReviewRating
    {
        return ReviewRating::tryFrom((string) $this->validated('rating'));
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('clinic.api.pagination.per_page'));
    }
}
