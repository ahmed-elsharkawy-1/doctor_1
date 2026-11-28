<?php

namespace App\Livewire\App\Concerns;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Services\V1\Branches\BranchScope;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "All branches" or one of them, above a report (Phase 8). The rules are
 * BranchScope's: a team member reads her own branches only.
 */
trait ChoosesReportBranch
{
    /** The branch chip; empty for "all branches". */
    #[Url(as: 'branch', except: '')]
    public string $reportBranch = '';

    public function chooseBranch(string $branchId): void
    {
        $this->reportBranch = ctype_digit($branchId) ? $branchId : '';
        $this->afterChoosingBranch();
    }

    /** Hook for a screen that keeps a page or an open item to reset. */
    protected function afterChoosingBranch(): void {}

    /** @return list<int>|null */
    protected function reportScope(): ?array
    {
        $scope = app(BranchScope::class);
        if (! ctype_digit($this->reportBranch)) {
            $this->reportBranch = '';
        }

        $branchId = $this->reportBranch === '' ? null : (int) $this->reportBranch;

        try {
            return $scope->resolve(auth()->user(), $this->clinic(), $branchId);
        } catch (ApiException) {
            // A branch from the address bar this account cannot read.
            $this->reportBranch = '';

            return $scope->resolve(auth()->user(), $this->clinic());
        }
    }

    /** @return Collection<int, Branch> */
    protected function branchChoices(): Collection
    {
        return app(BranchScope::class)->choices(auth()->user(), $this->clinic());
    }
}
