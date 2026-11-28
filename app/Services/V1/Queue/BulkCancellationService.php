<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cancelling a day, or several, at one or more branches (ELA-22): the
 * bookings still to come there, less any unticked. Each is cancelled for an
 * emergency (never a no-show) and sent the cancellation apology, whose
 * "book again" opens on its own branch.
 *
 * A team member reaches her own branches only, and "all branches" is all of
 * hers.
 */
class BulkCancellationService
{
    public function __construct(private readonly WhatsAppMessagingService $messaging) {}

    /**
     * The bookings it would cancel, grouped by branch.
     *
     * @param  list<string>  $dates  Y-m-d
     * @param  list<int>|null  $branchIds  null for every branch of hers
     * @return Collection<int, Collection<int, Booking>> keyed by branch id
     */
    public function candidates(Clinic $clinic, User $actor, array $dates, ?array $branchIds = null): Collection
    {
        $branches = $this->branches($clinic, $actor, $branchIds);
        $dates = $this->dates($clinic, $dates);

        $bookings = $clinic->bookings()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->where(function ($query) use ($dates): void {
                foreach ($dates as $date) {
                    $query->orWhereDate('visit_date', $date);
                }
            })
            ->pending()
            ->with(['patient', 'visitType'])
            ->orderBy('visit_date')
            ->orderBy('start_at')
            ->get();

        return $branches->mapWithKeys(fn (Branch $branch) => [
            $branch->id => $bookings->where('branch_id', $branch->id)->values(),
        ]);
    }

    /**
     * @param  list<string>  $dates
     * @param  list<int>|null  $branchIds
     * @param  list<int>  $exclude  bookings unticked: left as they are
     * @return array<string, mixed> the broadcast's result, plus `by_branch`
     */
    public function cancel(Clinic $clinic, User $actor, array $dates, ?array $branchIds, array $exclude = []): array
    {
        $groups = $this->candidates($clinic, $actor, $dates, $branchIds);
        $ids = $groups->flatten(1)->pluck('id')->diff($exclude)->values()->all();

        if ($ids === []) {
            throw ApiException::make(ApiErrorCode::NOTHING_TO_POSTPONE, __('booking.nothing_to_postpone'));
        }

        $byBranch = $groups->map(fn (Collection $bookings) => $bookings->whereIn('id', $ids)->count())->all();

        $result = $this->messaging->broadcast(
            $clinic,
            WhatsAppMessagingService::CANCELS_THE_DAY_KEY,
            bookingIds: $ids,
            branchIds: array_keys($byBranch),
        );

        return $result + ['by_branch' => $byBranch];
    }

    /** @return Collection<int, Branch> */
    private function branches(Clinic $clinic, User $actor, ?array $branchIds): Collection
    {
        $mine = $actor->accessibleBranches($clinic);

        if ($branchIds === null) {
            return $mine->values();
        }

        $chosen = $mine->whereIn('id', $branchIds)->values();

        if ($chosen->count() !== count(array_unique($branchIds))) {
            throw ApiException::make(ApiErrorCode::BRANCH_NOT_FOUND, __('settings.branch.not_found'), http: 404);
        }

        return $chosen;
    }

    /**
     * Today or later, a few at a time.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    private function dates(Clinic $clinic, array $dates): array
    {
        $today = $clinic->currentDay()->toDateString();
        $dates = array_values(array_unique(array_map(fn ($d) => Carbon::parse($d)->toDateString(), $dates)));
        $max = (int) config('clinic.bulk_cancel.max_days', 14);

        if ($dates === [] || count($dates) > $max || min($dates) < $today) {
            throw ApiException::make(ApiErrorCode::BULK_CANCEL_DAYS_INVALID, __('booking.bulk_cancel.days_invalid', ['days' => $max]), http: 422);
        }

        sort($dates);

        return $dates;
    }
}
