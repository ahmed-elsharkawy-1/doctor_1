<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Models\Booking;
use App\Services\V1\Queue\BulkCancellationService;
use App\Support\ApiResponse;
use App\Support\Wire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET and POST bulk-cancellations (ELA-22): the bookings a cancellation of
 * those days at those branches would cover, grouped by branch, and the
 * cancellation itself. Without `branch_ids`, every branch of this account.
 */
class BulkCancellationController extends V1Controller
{
    public function __construct(private readonly BulkCancellationService $bulk) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $clinic = $this->clinic($request);
        $groups = $this->bulk->candidates($clinic, $this->user($request), $validated['dates'], $validated['branch_ids'] ?? null);
        $names = $clinic->branches()->pluck('name', 'id');

        return ApiResponse::success([
            'total' => $groups->sum(fn ($bookings) => $bookings->count()),
            'branches' => $groups->map(fn ($bookings, $branchId) => [
                'id' => $branchId,
                'name' => $names[$branchId] ?? null,
                'count' => $bookings->count(),
                'bookings' => $bookings->map(fn (Booking $booking) => [
                    'booking_id' => $booking->id,
                    'date' => Wire::date($booking->visit_date),
                    'start_time' => $booking->start_at === null ? null : Wire::time($booking->start_at),
                    'patient' => ['code' => $booking->patient?->code, 'name' => $booking->patient?->name],
                ])->values()->all(),
            ])->values()->all(),
        ], __('booking.bulk_cancel.loaded'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, withExclusions: true);

        $result = $this->bulk->cancel(
            $this->clinic($request),
            $this->user($request),
            $validated['dates'],
            $validated['branch_ids'] ?? null,
            array_map('intval', $validated['exclude_booking_ids'] ?? []),
        );

        return ApiResponse::success($result, trans_choice('booking.bulk_cancel.cancelled', max(1, $result['cancelled_count']), ['count' => $result['cancelled_count']]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withExclusions = false): array
    {
        return $request->validate([
            'dates' => ['required', 'array', 'min:1'],
            'dates.*' => ['date_format:Y-m-d'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
        ] + ($withExclusions ? [
            'exclude_booking_ids' => ['nullable', 'array'],
            'exclude_booking_ids.*' => ['integer'],
        ] : []));
    }
}
