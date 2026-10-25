<?php

namespace App\Http\Requests\Api\V1\Queue;

use Illuminate\Foundation\Http\FormRequest;

class SearchQueueRequest extends FormRequest
{
    /** An autocomplete list, not a page of results. */
    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Two characters before anything is searched: one letter of a name
     * matches most of the day.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
