<?php

namespace App\Http\Requests\Api\V1\Queue;

use Illuminate\Foundation\Http\FormRequest;

class SearchQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The lengths and limits are config('clinic.queue_search'), shared with
     * the search box on the web queue.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.config('clinic.queue_search.min_chars'), 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('clinic.queue_search.max_limit')],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? config('clinic.queue_search.limit'));
    }
}
