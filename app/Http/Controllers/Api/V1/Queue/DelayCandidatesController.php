<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Models\Booking;
use App\Services\V1\Queue\DelayService;
use App\Support\ApiResponse;
use App\Support\Wire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET emergency/branches (ELA-22): who an emergency delay that day could
 * move, grouped by branch, for the app to tick and untick. Without
 * `branch_ids`, every branch this account works at.
 */
class DelayCandidatesController extends V1Controller
{
    public function __construct(private readonly DelayService $delay) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
        ]);

        $clinic = $this->clinic($request);
        $day = isset($validated['date']) ? Carbon::parse($validated['date'], $clinic->timezone) : $clinic->currentDay();
        $branches = $this->user($request)->accessibleBranches($clinic)
            ->when(isset($validated['branch_ids']), fn ($all) => $all->whereIn('id', $validated['branch_ids']))
            ->values();

        $groups = $this->delay->candidatesByBranch($clinic, $day, $branches);

        return ApiResponse::success([
            'date' => Wire::date($day),
            'options' => $this->delay->options(),
            'branches' => $branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'bookings' => $groups[$branch->id]->map(fn (Booking $booking) => [
                    'booking_id' => $booking->id,
                    'patient' => ['code' => $booking->patient?->code, 'name' => $booking->patient?->name],
                    'start_time' => Wire::time($booking->start_at),
                ])->values()->all(),
            ])->values()->all(),
        ], __('booking.delay.candidates_loaded'));
    }
}
