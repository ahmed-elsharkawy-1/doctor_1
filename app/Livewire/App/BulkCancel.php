<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Services\V1\Queue\BulkCancellationService;
use Illuminate\Contracts\View\View;

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

        try {
            $groups = app(BulkCancellationService::class)
                ->candidates($this->clinic(), auth()->user(), $this->dates(), array_map('intval', $this->scope));
        } catch (ApiException $e) {
            $this->notice ??= $e->getMessage();
            $this->failed = true;
        }

        return view('livewire.app.bulk-cancel', [
            'branches' => auth()->user()->accessibleBranches($this->clinic()),
            'groups' => $groups,
            'today' => $this->clinic()->currentDay()->toDateString(),
        ])->title(__('app.bulk_cancel.title'));
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
        $this->confirming = true;
    }

    public function dismiss(): void
    {
        $this->confirming = false;
    }

    public function cancel(): void
    {
        $this->requireAbility('bookings.manage');
        $this->confirming = false;

        try {
            $result = app(BulkCancellationService::class)->cancel(
                $this->clinic(),
                auth()->user(),
                $this->dates(),
                array_map('intval', $this->scope),
                array_map('intval', $this->excluded),
            );
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->notice = trans_choice('booking.bulk_cancel.cancelled', max(1, $result['cancelled_count']), ['count' => $result['cancelled_count']]);
        $this->failed = false;
        $this->excluded = [];
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
