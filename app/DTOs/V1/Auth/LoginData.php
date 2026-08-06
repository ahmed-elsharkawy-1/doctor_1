<?php

namespace App\DTOs\V1\Auth;

/**
 * Credentials submitted to POST /auth/login.
 */
final class LoginData
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $deviceName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            email: strtolower(trim((string) $validated['email'])),
            password: (string) $validated['password'],
            deviceName: isset($validated['device_name']) ? (string) $validated['device_name'] : null,
        );
    }

    public function tokenName(): string
    {
        return $this->deviceName ?: config('clinic.api.token_name');
    }
}
