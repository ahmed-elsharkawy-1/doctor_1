<?php

namespace App\Services\V1\Branches;

use App\DTOs\V1\Branches\BranchData;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Clinic;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Adding, editing, deactivating and reactivating a doctor's branches (ELA-16).
 *
 * The first branch reads and writes the clinic's own address and pin rather
 * than copies of them. The clinic row is still what the public page, the
 * tracking page and the messages read, and what the operator edits in the
 * panel, so the two can never disagree. Later branches keep their own.
 */
class BranchService
{
    /** @return Collection<int, Branch> */
    public function list(Clinic $clinic): Collection
    {
        return $clinic->branches()->get();
    }

    public function find(Clinic $clinic, int $branchId): Branch
    {
        $branch = $clinic->branches()->whereKey($branchId)->first();

        if ($branch === null) {
            throw ApiException::make(ApiErrorCode::BRANCH_NOT_FOUND, __('settings.branch.not_found'), http: 404);
        }

        return $branch;
    }

    /**
     * A second branch only once the rest of multi-branch has shipped: until
     * then it would have no hours or bookings of its own.
     */
    public function canAdd(): bool
    {
        return (bool) config('clinic.branches.multi_enabled');
    }

    public function create(Clinic $clinic, BranchData $data): Branch
    {
        if (! $this->canAdd()) {
            throw ApiException::make(ApiErrorCode::BRANCH_ADDING_DISABLED, __('settings.branch.adding_disabled'), http: 403);
        }

        $this->ensureUniqueName($clinic, $data->name);

        return $this->guardNameRace(fn () => $clinic->branches()->create([
            'name' => $data->name,
            'address' => $data->address,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'phone' => $data->phone,
            'is_active' => true,
            'sort_order' => ((int) $clinic->branches()->max('sort_order')) + 1,
        ]));
    }

    /**
     * Applies to new messages and pages only: nothing already sent is
     * rewritten (ELA-16).
     */
    public function update(Branch $branch, BranchData $data): Branch
    {
        $clinic = $branch->clinic;
        $this->ensureUniqueName($clinic, $data->name, except: $branch);

        return DB::transaction(function () use ($branch, $clinic, $data): Branch {
            $place = [
                'address' => $data->address,
                'latitude' => $data->latitude,
                'longitude' => $data->longitude,
            ];

            if ($branch->isFirst()) {
                $clinic->update($place);
                $place = ['address' => null, 'latitude' => null, 'longitude' => null];
            }

            $this->guardNameRace(fn () => $branch->update(['name' => $data->name, 'phone' => $data->phone] + $place));

            return $branch->refresh();
        });
    }

    /**
     * Hidden from booking from now on. Bookings already made stay and run as
     * usual (queue, reminders, WhatsApp) until done: nothing is cancelled
     * here (ELA-16).
     */
    public function deactivate(Branch $branch): Branch
    {
        return DB::transaction(function () use ($branch): Branch {
            // Lock every branch of the clinic, this one included, so two
            // deactivations at once queue up rather than deadlock: the second
            // then sees the first and is refused as the last active one.
            $branches = Branch::query()
                ->where('clinic_id', $branch->clinic_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $others = $branches->where('id', '!==', $branch->id)->where('is_active', true)->count();

            if ($others === 0) {
                throw ApiException::make(ApiErrorCode::BRANCH_LAST_ACTIVE, __('settings.branch.last_active'), http: 409);
            }

            $branch->update(['is_active' => false]);

            return $branch;
        });
    }

    public function activate(Branch $branch): Branch
    {
        $branch->update(['is_active' => true]);

        return $branch;
    }

    /**
     * Two saves of the same name at once both pass ensureUniqueName(); the
     * unique index stops the second, and it gets the same answer as if it
     * had come later.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardNameRace(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::make(ApiErrorCode::BRANCH_DUPLICATE_NAME, __('settings.branch.duplicate_name'), http: 409);
        }
    }

    private function ensureUniqueName(Clinic $clinic, string $name, ?Branch $except = null): void
    {
        $taken = $clinic->branches()
            ->where('name', $name)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw ApiException::make(ApiErrorCode::BRANCH_DUPLICATE_NAME, __('settings.branch.duplicate_name'), http: 409);
        }
    }
}
