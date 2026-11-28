<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\V1\Queue\BulkCancellationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Cancelling days at one or more branches (ELA-22): branches and days first,
 * then the bookings grouped by branch with any unticked, a count per branch,
 * and one confirmation. Every rule is BulkCancellationService's.
 */
class BulkCancel extends ClinicComponent
{
    /** @var list<string> branch ids */
    public array $scope = [];

    public string $from = '';

    public string $to = '';

    /** @var list<string> booking ids unticked */
    public array $excluded = [];

    public bool $confirming = false;

    /** @var list<int> the bookings on screen when "cancel" was pressed */
    public array $shown = [];

    /** Why the days chosen show nothing; worked out afresh on each render. */
    public ?string $dateError = null;

    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        $this->requireAbility('bookings.manage');

        $this->scope = [(string) $this->branch()->id];
        $this->from = $this->to = $this->clinic()->currentDay()->toDateString();
    }

    public function render(): View
    {
        $this->requireAbility('bookings.manage');

        $groups = collect();
        $this->dateError = null;

        try {
            $groups = $this->candidates();
        } catch (ApiException $e) {
            $this->dateError = $e->getMessage();
        }

        return view('livewire.app.bulk-cancel', [
            'branches' => auth()->user()->accessibleBranches($this->clinic()),
            'groups' => $groups,
            'today' => $this->clinic()->currentDay()->toDateString(),
        ])->title(__('app.bulk_cancel.title'));
    }

    /** Any change to the branches or days asks again before cancelling. */
    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true) || str_starts_with($property, 'scope')) {
            $this->confirming = false;
            $this->notice = null;
            $this->failed = false;
        }
    }

    /** "To" never falls before "from". */
    public function updatedFrom(): void
    {
        if ($this->to === '' || $this->safeDate($this->to)->lt($this->safeDate($this->from))) {
            $this->to = $this->from;
        }
    }

    public function allBranches(): void
    {
        $this->scope = auth()->user()->accessibleBranches($this->clinic())->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->confirming = false;
    }

    public function toggleExcluded(int $bookingId): void
    {
        $id = (string) $bookingId;
        $this->excluded = in_array($id, $this->excluded, true)
            ? array_values(array_diff($this->excluded, [$id]))
            : [...$this->excluded, $id];
        $this->confirming = false;
    }

    public function confirm(): void
    {
        $this->notice = null;
        $this->failed = false;

        try {
            $this->shown = $this->candidates()->flatten(1)->pluck('id')->all();
        } catch (ApiException) {
            return;
        }

        $this->confirming = $this->shown !== [];
    }

    public function dismiss(): void
    {
        $this->confirming = false;
    }

    public function cancel(): void
    {
        $this->requireAbility('bookings.manage');

        // A second tap arriving with the first finds the box closed.
        if (! $this->confirming) {
            return;
        }

        $this->confirming = false;

        $excluded = array_map('intval', $this->excluded);

        try {
            $result = app(BulkCancellationService::class)->cancel(
                $this->clinic(),
                auth()->user(),
                $this->dates(),
                array_map('intval', $this->scope),
                // What was on screen and left ticked; nothing booked since.
                array_values(array_diff($this->shown, $excluded)),
            );
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->notice = trans_choice('booking.bulk_cancel.cancelled', max(1, $result['cancelled_count']), ['count' => $result['cancelled_count']]);
        $new = $result['unlisted_count'] - count(array_intersect($this->shown, $excluded));

        if ($new > 0) {
            $this->notice .= ' — '.trans_choice('booking.bulk_cancel.unlisted', $new, ['count' => $new]);
        }

        $this->failed = false;
        // The unticked stay unticked: they are still there, kept.
        $this->shown = [];
    }

    /** @return Collection<int, Collection<int, Booking>> */
    private function candidates(): Collection
    {
        return app(BulkCancellationService::class)
            ->candidates($this->clinic(), auth()->user(), $this->dates(), array_map('intval', $this->scope));
    }

    /** @return list<string> every day from `from` to `to` */
    private function dates(): array
    {
        $from = $this->safeDate($this->from);
        $to = $this->safeDate($this->to ?: $this->from);
        $dates = [];

        for ($day = $from->copy(); $day->lte($to) && count($dates) <= 31; $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }
}
