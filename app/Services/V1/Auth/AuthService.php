<?php

namespace App\Services\V1\Auth;

use App\DTOs\V1\Auth\LoginData;
use App\Enums\AuthErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Results\V1\Auth\AuthenticatedUserResult;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * @throws ApiException when the credentials are wrong, the account is
     *                      disabled, or the role has no mobile access
     */
    public function login(LoginData $data): AuthenticatedUserResult
    {
        $user = $this->authenticate($data->email, $data->password);

        $token = $user->createToken($data->tokenName())->plainTextToken;

        return new AuthenticatedUserResult($user, $token);
    }

    /**
     * Who may sign in to a clinic client, and why they may not. The mobile app
     * and the clinic web app ask the same three questions, so they ask them
     * here rather than each keeping their own copy.
     *
     * @throws ApiException when the credentials are wrong, the account is
     *                      disabled, or the role has no client access
     */
    public function authenticate(string $email, string $password): User
    {
        $user = User::with(['clinics.specialty'])
            ->where('email', strtolower($email))
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ApiException::make(
                AuthErrorCode::INVALID_CREDENTIALS,
                __('auth.invalid_credentials'),
                http: 401,
            );
        }

        if (! $user->is_active) {
            throw ApiException::make(
                AuthErrorCode::ACCOUNT_INACTIVE,
                __('auth.account_inactive'),
                http: 403,
            );
        }

        if (! $user->role->usesMobileApp()) {
            throw ApiException::make(
                AuthErrorCode::FORBIDDEN_ROLE,
                __('auth.role_not_allowed'),
                http: 403,
            );
        }

        return $user;
    }

    /**
     * Revokes only the token this request arrived with, so signing out on one
     * device does not sign the account out everywhere.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }
    }

    public function profile(User $user): AuthenticatedUserResult
    {
        $user->loadMissing(['clinics.specialty']);

        return new AuthenticatedUserResult($user);
    }
}
