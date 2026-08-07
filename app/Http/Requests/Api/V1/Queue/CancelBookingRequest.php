<?php

namespace App\Http\Requests\Api\V1\Queue;

use App\Enums\CancelReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelBookingRequest extends FormRequest
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
            // Only the reasons the secretary picks herself — `emergency` comes
            // from the postpone flow and `incomplete` from the nightly job.
            'reason' => [
                'required',
                Rule::in(array_column(CancelReason::selectable(), 'value')),
            ],
        ];
    }

    public function reason(): CancelReason
    {
        return CancelReason::from($this->validated('reason'));
    }
}
