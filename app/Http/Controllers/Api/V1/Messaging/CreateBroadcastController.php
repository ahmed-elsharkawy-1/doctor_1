<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Messaging\BroadcastRequest;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class CreateBroadcastController extends V1Controller
{
    public function __construct(private readonly WhatsAppMessagingService $messaging) {}

    public function __invoke(BroadcastRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $date = $request->filled('date')
            ? Carbon::parse($request->validated('date'), $clinic->timezone)
            : null;

        $result = $this->messaging->broadcast(
            $clinic,
            (string) $request->validated('template_key'),
            $date,
            $request->bookingIds(),
        );

        return ApiResponse::success($result, __('messages.sent'));
    }
}
