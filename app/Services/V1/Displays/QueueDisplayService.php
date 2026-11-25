<?php

namespace App\Services\V1\Displays;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\QueueDisplay;
use App\Models\User;
use App\Services\V1\Queue\QueuePositionService;
use App\Services\V1\Queue\QueueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The waiting-room TVs and what each shows (ELA-19).
 *
 * Managed by whoever may manage branches, and only for their branches: a TV
 * is linked to one branch, and moving it is the same power.
 */
class QueueDisplayService
{
    public function __construct(
        private readonly QueuePositionService $positions,
        private readonly QueueService $queue,
    ) {}

    /** @return Collection<int, QueueDisplay> */
    public function list(Clinic $clinic, User $actor): Collection
    {
        return QueueDisplay::query()
            ->where('clinic_id', $clinic->id)
            ->whereIn('branch_id', $actor->accessibleBranches($clinic)->pluck('id'))
            ->with('branch')
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();
    }

    public function create(Clinic $clinic, User $actor, int $branchId, string $name): QueueDisplay
    {
        return QueueDisplay::query()->create([
            'clinic_id' => $clinic->id,
            'branch_id' => $this->branch($clinic, $actor, $branchId)->id,
            'name' => trim($name),
        ]);
    }

    public function update(Clinic $clinic, User $actor, int $displayId, int $branchId, string $name): QueueDisplay
    {
        $display = $this->find($clinic, $actor, $displayId);
        $display->update([
            'branch_id' => $this->branch($clinic, $actor, $branchId)->id,
            'name' => trim($name),
        ]);

        return $display->load('branch');
    }

    /**
     * A new address for the TV: the old one stops working at once, for a
     * link that was shared further than it should have been.
     */
    public function rotate(Clinic $clinic, User $actor, int $displayId): QueueDisplay
    {
        $display = $this->find($clinic, $actor, $displayId);
        $display->update(['token' => QueueDisplay::newToken()]);

        return $display;
    }

    public function delete(Clinic $clinic, User $actor, int $displayId): void
    {
        $this->find($clinic, $actor, $displayId)->delete();
    }

    /**
     * What a TV shows now: today's queue at its branch, or an idle screen
     * outside the branch's hours, on its day off or a holiday.
     *
     * @return array{display: QueueDisplay, branch: Branch, clinic: Clinic, idle: bool, now: Carbon, with_doctor: Collection<int, Booking>, next: Collection<int, Booking>, waiting: int}
     */
    public function board(QueueDisplay $display): array
    {
        $display->loadMissing(['branch', 'clinic.doctor']);
        $clinic = $display->clinic;
        $branch = $display->branch;
        $now = Carbon::now($clinic->timezone);

        $queue = $this->queue->sortBookings($this->positions->today($clinic, $branch));
        $withDoctor = $queue->where('status', BookingStatus::WITH_DOCTOR)->values();
        $line = $queue->reject(fn (Booking $b) => $b->status === BookingStatus::WITH_DOCTOR)->values();

        // "Is this TV on?" needs the minute, not every reload.
        if ($display->last_seen_at === null || $display->last_seen_at->lt(now()->subMinute())) {
            $display->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return [
            'display' => $display,
            'branch' => $branch,
            'clinic' => $clinic,
            // Someone still waiting keeps the board up, whatever the clock says.
            'idle' => $queue->isEmpty() && ! $this->isOpenAround($branch, $clinic, $now),
            'now' => $now,
            'with_doctor' => $withDoctor,
            'next' => $line->take((int) config('clinic.displays.next_count')),
            'waiting' => $line->count(),
        ];
    }

    /**
     * Within today's hours at the branch, with a margin either side for the
     * room filling up and emptying out.
     */
    private function isOpenAround(Branch $branch, Clinic $clinic, Carbon $now): bool
    {
        $day = $clinic->currentDay();

        if ($branch->isClosedOn($day)) {
            return false;
        }

        $schedule = $branch->scheduleFor(DayOfWeek::fromDate($day));

        if ($schedule === null || ! $schedule->is_open) {
            return false;
        }

        $margin = (int) config('clinic.displays.margin_minutes');

        foreach ($schedule->periods as $period) {
            [$opens, $closes] = $period->boundsOn($day, $clinic->timezone);

            if ($now->between($opens->copy()->subMinutes($margin), $closes->copy()->addMinutes($margin))) {
                return true;
            }
        }

        return false;
    }

    private function find(Clinic $clinic, User $actor, int $displayId): QueueDisplay
    {
        $display = QueueDisplay::query()
            ->where('clinic_id', $clinic->id)
            ->whereKey($displayId)
            ->first();

        if ($display === null || ! $actor->accessibleBranches($clinic)->contains('id', $display->branch_id)) {
            throw ApiException::make(ApiErrorCode::DISPLAY_NOT_FOUND, __('settings.display.not_found'), http: 404);
        }

        return $display;
    }

    private function branch(Clinic $clinic, User $actor, int $branchId): Branch
    {
        $branch = $actor->accessibleBranches($clinic)->firstWhere('id', $branchId);

        if ($branch === null) {
            throw ApiException::make(ApiErrorCode::BRANCH_NOT_FOUND, __('settings.branch.not_found'), http: 404);
        }

        return $branch;
    }
}
