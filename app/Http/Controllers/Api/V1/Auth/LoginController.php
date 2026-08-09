<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\DTOs\V1\Auth\LoginData;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Services\V1\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LoginController extends V1Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(LoginData::fromArray($request->validated()));

        return ApiResponse::success($result->toLoginArray(), __('auth.logged_in'));
    }
}
