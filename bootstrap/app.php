<?php

use App\Enums\ApiErrorCode;
use App\Enums\AuthErrorCode;
use App\Http\Middleware\ResolveClinic;
use App\Http\Middleware\SetApiLocale;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetApiLocale::class,
        ]);

        $middleware->alias([
            'clinic' => ResolveClinic::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every failure reaching the API is rendered as the standard envelope
         * (SPEC §6.1). The Flutter app never receives an HTML error page.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::validationFailed($e->errors()),

                $e instanceof AuthenticationException => ApiResponse::error(
                    AuthErrorCode::ACCESS_TOKEN_MISSING,
                    __('auth.token_missing'),
                    http: 401,
                ),

                $e instanceof AuthorizationException => ApiResponse::error(
                    AuthErrorCode::FORBIDDEN_ROLE,
                    __('auth.forbidden'),
                    http: 403,
                ),

                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    ApiErrorCode::RESOURCE_NOT_FOUND,
                    __('messages.not_found'),
                    http: 404,
                ),

                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    'HTTP_'.$e->getStatusCode(),
                    $e->getMessage() ?: __('messages.request_failed'),
                    http: $e->getStatusCode(),
                ),

                default => ApiResponse::error(
                    AuthErrorCode::INTERNAL_SERVER_ERROR,
                    config('app.debug') ? $e->getMessage() : __('messages.server_error'),
                    details: config('app.debug') ? ['exception' => $e::class] : null,
                    http: 500,
                ),
            };
        });
    })->create();
