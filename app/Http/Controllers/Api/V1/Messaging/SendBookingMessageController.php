<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Messaging\BookingMessageRequest;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SendBookingMessageController extends V1Controller
{
    public function __construct(private readonly WhatsAppMessagingService $messaging) {}

    public function __invoke(BookingMessageRequest $request, int $booking): JsonResponse
    {
        $result = $this->messaging->sendForBooking(
            $this->clinic($request),
            $booking,
            (string) $request->validated('template_key'),
        );

        return ApiResponse::success($result, __('messages.sent'));
    }
}
