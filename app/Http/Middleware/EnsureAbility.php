<?php

namespace App\Http\Middleware;

use App\Enums\AuthErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on one of the abilities declared by App\Enums\UserRole.
 *
 *     Route::put(...)->middleware('ability:settings.manage');
 */
class EnsureAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        foreach ($abilities as $ability) {
            if ($user?->hasAbility($ability)) {
                return $next($request);
            }
        }

        return ApiResponse::error(
            AuthErrorCode::FORBIDDEN_ROLE,
            __('auth.forbidden'),
            http: 403,
        );
    }
}
