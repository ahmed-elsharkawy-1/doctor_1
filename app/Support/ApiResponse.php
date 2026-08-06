<?php

namespace App\Support;

use App\Enums\ApiErrorCode;
use App\Enums\AuthErrorCode;
use App\Enums\ResponseStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The single place an API response is shaped — see SPEC §6.1.
 *
 * Controllers never build a response array by hand. Every response the mobile
 * app receives is one of these three envelopes, including framework errors
 * (see bootstrap/app.php).
 */
final class ApiResponse
{
    public static function success(array|object|null $data = null, string $message = '', int $http = 200): JsonResponse
    {
        return response()->json([
            'status' => ResponseStatus::SUCCESS->value,
            'message' => $message,
            'data' => $data,
        ], $http);
    }

    public static function created(array|object|null $data = null, string $message = ''): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function error(
        AuthErrorCode|ApiErrorCode|string $code,
        string $message,
        ?array $details = null,
        int $http = 400,
    ): JsonResponse {
        return response()->json([
            'status' => ResponseStatus::ERROR->value,
            'message' => $message,
            'error' => [
                'code' => $code instanceof \BackedEnum ? $code->value : $code,
                'details' => $details === [] ? null : $details,
                'fields' => null,
            ],
        ], $http);
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    public static function validationFailed(array $fields, ?string $message = null): JsonResponse
    {
        return response()->json([
            'status' => ResponseStatus::ERROR->value,
            'message' => $message ?? __('messages.validation_failed'),
            'error' => [
                'code' => AuthErrorCode::VALIDATION_FAILED->value,
                'details' => null,
                'fields' => $fields,
            ],
        ], 422);
    }

    /**
     * Paginated payload — see SPEC §6.5. `items` is already-shaped data, not
     * raw models.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<mixed>  $items
     */
    public static function paginated(LengthAwarePaginator $paginator, array $items, string $message = ''): JsonResponse
    {
        return self::success([
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], $message);
    }
}
