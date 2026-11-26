<?php

namespace App\Livewire\App\Settings;

use App\Http\Requests\Api\V1\Branches\SaveBranchRequest;
use App\Services\V1\Branches\BranchService;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;

/**
 * Settings → Branches (ELA-16): the places the doctor works from, with one
 * form for adding and editing.
 *
 * Every rule (unique names, the last active branch, whether a second branch
 * may be added yet) lives in BranchService; the screen asks and reports.
 */
class Branches extends SettingsComponent
{
    protected string $ability = 'branches.manage';

    /** The branch being edited, 0 while adding, null while the form is shut. */
    public ?int $editing = null;

    /** The branch waiting for its deactivation to be confirmed. */
    public ?int $confirmingDeactivate = null;

    /** The branch whose waiting list is being switched off with people on it. */
    public ?int $confirmingListOff = null;

    /** How many are on it, to say before she confirms. */
    public int $listOffWaiting = 0;

    public string $name = '';

    public string $address = '';

    public string $map_link = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $phone = '';

    public function render(): View
    {
        $clinic = $this->clinic();

        return view('livewire.app.settings.branches', [
            'branches' => $this->service()->list($clinic, auth()->user()),
            'canAdd' => $this->service()->canAdd(),
            // A branch's own list is a multi-branch setting: with one branch
            // the operator's clinic switch is the only one (ELA-21).
            'branchListsEditable' => $this->service()->branchListsEditable($clinic),
            'mainPhone' => $clinic->phone === null ? null : PhoneNumber::tryParse($clinic->phone, $clinic->country_code)?->national(),
        ])->title(__('app.settings.branches'));
    }

    public function startAdding(): void
    {
        $this->resetForm();
        $this->editing = 0;
    }

    public function startEditing(int $branchId): void
    {
        $branch = $this->clinic()->branches()->find($branchId);

        if ($branch === null || ! auth()->user()->canUseBranch($branch)) {
            return;
        }

        $coordinates = $branch->coordinates();
        [$lat, $lng] = $coordinates === null ? ['', ''] : explode(',', $coordinates);

        $this->resetForm();
        $this->editing = $branch->id;
        $this->name = $branch->name;
        $this->address = (string) $branch->displayAddress();
        $this->latitude = $lat;
        $this->longitude = $lng;
        $this->phone = filled($branch->phone) ? (string) $branch->contactPhone()?->national() : '';
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $clinic = $this->clinic();
        $input = $this->validate(SaveBranchRequest::rulesFor($clinic->country_code));
        $data = SaveBranchRequest::toData($input, $clinic->country_code);

        $adding = $this->editing === 0;

        $this->run(
            fn () => $adding
                ? $this->service()->create($clinic, $data, auth()->user())
                : $this->service()->update($this->service()->find($clinic, (int) $this->editing, auth()->user()), $data),
            __($adding ? 'app.settings.branch_added' : 'app.settings.branch_saved'),
        );

        if (! $this->failed) {
            $this->resetForm();
        }
    }

    public function askToDeactivate(int $branchId): void
    {
        $this->confirmingDeactivate = $branchId;
    }

    public function deactivate(): void
    {
        $branchId = (int) $this->confirmingDeactivate;
        $this->confirmingDeactivate = null;

        $this->run(
            fn () => $this->service()->deactivate($this->service()->find($this->clinic(), $branchId, auth()->user())),
            __('app.settings.branch_deactivated'),
        );
    }

    /**
     * The branch's own waiting list on or off (ELA-21). Off with patients on
     * it asks first, saying how many; they stay on it either way.
     */
    public function toggleWaitingList(int $branchId, bool $enabled, bool $confirm = false): void
    {
        $branch = $this->service()->find($this->clinic(), $branchId, auth()->user());

        // The state she asked for, never "the other one": a second tab or a
        // colleague may have changed it since this screen was drawn.
        $details = $this->run(
            fn () => $this->service()->setWaitingList($branch, $enabled, $confirm),
            __($enabled ? 'settings.branch.waiting_list_on' : 'settings.branch.waiting_list_off'),
        );

        if (isset($details['waiting_count'])) {
            $this->notice = null;
            $this->failed = false;
            $this->confirmingListOff = $branchId;
            $this->listOffWaiting = (int) $details['waiting_count'];

            return;
        }

        $this->confirmingListOff = null;
    }

    public function confirmWaitingListOff(): void
    {
        if ($this->confirmingListOff !== null) {
            $this->toggleWaitingList($this->confirmingListOff, enabled: false, confirm: true);
        }
    }

    public function activate(int $branchId): void
    {
        $this->run(
            fn () => $this->service()->activate($this->service()->find($this->clinic(), $branchId, auth()->user())),
            __('app.settings.branch_activated'),
        );
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'name', 'address', 'map_link', 'latitude', 'longitude', 'phone']);
        $this->resetValidation();
    }

    private function service(): BranchService
    {
        return app(BranchService::class);
    }
}
