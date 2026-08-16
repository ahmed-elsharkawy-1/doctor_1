<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListMessageTemplatesController extends V1Controller
{
    public function __construct(private readonly WhatsAppMessagingService $messaging) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'items' => $this->messaging->templates()->map(fn ($template) => [
                'key' => $template->key,
                'category' => $template->category,
                'body_ar' => $template->body_ar,
            ])->values()->all(),
        ], __('messages.loaded'));
    }
}
