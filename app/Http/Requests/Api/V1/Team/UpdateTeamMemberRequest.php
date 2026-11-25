<?php

namespace App\Http\Requests\Api\V1\Team;

use App\Enums\StaffPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
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
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(StaffPermission::class)],
        ];
    }
}
