<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Queue\DelayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The delays the app offers, already worded, so the list and its Arabic
 * live in one place.
 */
class DelayOptionsController extends V1Controller
{
    public function __construct(private readonly DelayService $delay) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            ['items' => $this->delay->options()],
            __('booking.delay.options_loaded'),
        );
    }
}
