<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'phone' => [
                'required',
                'string',
                'max:32',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (PhoneNumber::tryParse((string) $value) === null) {
                        $fail(__('patients.invalid_phone'));
                    }
                },
            ],
            // No minimum length: on login, any wrong password is simply wrong.
            // A length rule here would answer with VALIDATION_FAILED instead of
            // INVALID_CREDENTIALS, telling the caller their guess was too short
            // to be anyone's password.
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
