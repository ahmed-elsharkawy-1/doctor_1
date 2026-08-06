<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends V1Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->authService->profile($this->user($request));

        return ApiResponse::success($result->toArray(), __('auth.profile_loaded'));
    }
}
