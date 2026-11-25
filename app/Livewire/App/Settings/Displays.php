<?php

namespace App\Livewire\App\Settings;

use App\Services\V1\Displays\QueueDisplayService;
use Illuminate\Contracts\View\View;

/**
 * Settings → Displays (ELA-19): the waiting-room TVs, each linked to one
 * branch. Changing which branch a TV shows is "Manage branches", within the
 * account's own branches.
 */
class Displays extends SettingsComponent
{
    protected string $ability = 'branches.manage';

    public string $name = '';

    public ?int $branchId = null;

    /** @var array<int, array{name: string, branch_id: string}> edits, per display */
    public array $edits = [];

    public function mount(): void
    {
        parent::mount();
        $this->branchId = $this->branch()->id;
        $this->load();
    }

    public function render(): View
    {
        return view('livewire.app.settings.displays', [
            'displays' => $this->service()->list($this->clinic(), auth()->user()),
            'branches' => auth()->user()->accessibleBranches($this->clinic()),
        ])->title(__('app.settings.displays'));
    }

    public function add(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:60'], 'branchId' => ['required', 'integer']]);

        $this->run(
            fn () => $this->service()->create($this->clinic(), auth()->user(), (int) $this->branchId, $this->name),
            __('app.settings.display_added'),
        );

        if (! $this->failed) {
            $this->name = '';
            $this->load();
        }
    }

    public function save(int $displayId): void
    {
        $edit = $this->edits[$displayId] ?? null;

        if ($edit === null || trim($edit['name']) === '') {
            return;
        }

        $this->run(
            fn () => $this->service()->update($this->clinic(), auth()->user(), $displayId, (int) $edit['branch_id'], mb_substr($edit['name'], 0, 60)),
            __('app.settings.saved'),
        );
        $this->load();
    }

    public function rotate(int $displayId): void
    {
        $this->run(
            fn () => $this->service()->rotate($this->clinic(), auth()->user(), $displayId),
            __('app.settings.display_rotated'),
        );
    }

    public function remove(int $displayId): void
    {
        $this->run(
            fn () => $this->service()->delete($this->clinic(), auth()->user(), $displayId),
            __('app.settings.display_removed'),
        );
        $this->load();
    }

    private function load(): void
    {
        $this->edits = [];

        foreach ($this->service()->list($this->clinic(), auth()->user()) as $display) {
            $this->edits[$display->id] = ['name' => $display->name, 'branch_id' => (string) $display->branch_id];
        }
    }

    private function service(): QueueDisplayService
    {
        return app(QueueDisplayService::class);
    }
}
