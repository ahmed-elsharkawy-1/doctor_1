<?php

namespace App\Http\Requests\Api\V1\Queue;

use App\Services\V1\Queue\DelayService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmergencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `action` leaves room for the emergency's other actions; `delay` is the
     * only one so far. `booking_id` is the first patient to move.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['delay'])],
            'booking_id' => ['required', 'integer'],
            'delay_minutes' => ['required', 'integer', Rule::in(app(DelayService::class)->allowedMinutes())],
        ];
    }
}
