<?php

namespace App\Livewire\App\Settings;

use App\Enums\StaffPermission;
use App\Services\V1\Team\TeamService;
use Illuminate\Contracts\View\View;

/**
 * Settings → Team (ELA-17): each team member's branches and extra
 * permissions, set by the doctor. The doctor's own row is shown, not edited.
 */
class Team extends SettingsComponent
{
    protected string $ability = 'team.manage';

    /** @var array<int, array{branches: list<string>, permissions: list<string>}> */
    public array $members = [];

    public function mount(): void
    {
        parent::mount();
        $this->load();
    }

    public function render(): View
    {
        $clinic = $this->clinic();

        return view('livewire.app.settings.team', [
            'team' => app(TeamService::class)->members($clinic),
            'branches' => $clinic->branches()->get(),
            'permissionOptions' => StaffPermission::options(),
        ])->title(__('app.settings.team'));
    }

    public function save(int $memberId): void
    {
        $entry = $this->members[$memberId] ?? ['branches' => [], 'permissions' => []];

        $this->run(
            fn () => app(TeamService::class)->update(
                $this->clinic(),
                $memberId,
                array_map('intval', $entry['branches']),
                $entry['permissions'],
            ),
            __('app.settings.team_saved'),
        );

        $this->load();
    }

    private function load(): void
    {
        $this->members = [];

        foreach (app(TeamService::class)->members($this->clinic()) as $member) {
            if ($member->isOwner()) {
                continue;
            }

            $this->members[$member->id] = [
                // Strings: checkbox values come back from the browser as strings.
                'branches' => $member->branches->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                'permissions' => array_values($member->permissions ?? []),
            ];
        }
    }
}
