<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Enums\AuthErrorCode;
use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A business-rule failure that the mobile app is expected to handle.
 *
 * Services throw this instead of returning error tuples; it renders itself as
 * the standard error envelope (SPEC §6.1), so a controller never has to
 * branch on failure.
 */
class ApiException extends Exception
{
    private function __construct(
        public readonly AuthErrorCode|ApiErrorCode|string $errorCode,
        string $message,
        public readonly ?array $details = null,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }

    public static function make(
        AuthErrorCode|ApiErrorCode|string $code,
        string $message,
        ?array $details = null,
        int $http = 400,
    ): self {
        return new self($code, $message, $details, $http);
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            $this->errorCode,
            $this->getMessage(),
            $this->details,
            $this->httpStatus,
        );
    }
}
