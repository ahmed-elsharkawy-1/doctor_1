<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Booking\BookingDaysResult;
use App\Services\V1\Booking\BookingDaysService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingDaysController extends V1Controller
{
    public function __construct(private readonly BookingDaysService $bookingDays) {}

    public function __invoke(Request $request): JsonResponse
    {
        $days = $this->bookingDays->window($this->clinic($request));

        return ApiResponse::success(
            (new BookingDaysResult($days))->toArray(),
            __('booking.days_loaded'),
        );
    }
}
