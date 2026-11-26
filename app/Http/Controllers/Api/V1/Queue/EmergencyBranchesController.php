<?php

namespace App\Http\Controllers\Api\V1\Queue;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Queue\DelayResult;
use App\Services\V1\Queue\DelayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST emergency/branches (ELA-22): an emergency delay over one or more of
 * this account's branches, each by its own minutes, leaving out whoever was
 * unticked. Patients already with the doctor are never moved.
 */
class EmergencyBranchesController extends V1Controller
{
    public function __construct(private readonly DelayService $delay) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branches' => ['required', 'array', 'min:1'],
            'branches.*.branch_id' => ['required', 'integer', 'distinct'],
            'branches.*.minutes' => ['required', 'integer'],
            'branches.*.exclude_booking_ids' => ['nullable', 'array'],
            'branches.*.exclude_booking_ids.*' => ['integer'],
        ]);

        $clinic = $this->clinic($request);
        $day = isset($validated['date']) ? Carbon::parse($validated['date'], $clinic->timezone) : $clinic->currentDay();

        $plan = [];
        foreach ($validated['branches'] as $step) {
            $plan[(int) $step['branch_id']] = [
                'minutes' => (int) $step['minutes'],
                'exclude' => array_map('intval', $step['exclude_booking_ids'] ?? []),
            ];
        }

        $outcome = $this->delay->delayBranches($clinic, $this->user($request), $day, $plan);

        return ApiResponse::success(
            (new DelayResult($outcome))->toArray(),
            trans_choice('booking.delay.delayed', max(1, $outcome->bookings->count())),
        );
    }
}
