<?php

namespace App\Http\Middleware;

use App\Enums\AuthErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the caller's clinic on the request, and refuses the call if there
 * isn't a usable one.
 *
 * This is what makes `clinic_id` un-spoofable: no endpoint ever accepts it
 * from the client (SPEC §6.6).
 */
class ResolveClinic
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                AuthErrorCode::ACCESS_TOKEN_MISSING,
                __('auth.token_missing'),
                http: 401,
            );
        }

        if (! $user->is_active) {
            return ApiResponse::error(
                AuthErrorCode::ACCOUNT_INACTIVE,
                __('auth.account_inactive'),
                http: 403,
            );
        }

        if (! $user->role->usesMobileApp()) {
            return ApiResponse::error(
                AuthErrorCode::FORBIDDEN_ROLE,
                __('auth.role_not_allowed'),
                http: 403,
            );
        }

        $clinic = $user->activeClinic();

        if ($clinic === null) {
            return ApiResponse::error(
                AuthErrorCode::CLINIC_NOT_ASSIGNED,
                __('auth.clinic_not_assigned'),
                http: 403,
            );
        }

        if (! $clinic->is_active) {
            return ApiResponse::error(
                AuthErrorCode::CLINIC_INACTIVE,
                __('auth.clinic_inactive'),
                http: 403,
            );
        }

        $request->attributes->set('clinic', $clinic);

        return $next($request);
    }
}
