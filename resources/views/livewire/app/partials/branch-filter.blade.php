{{-- "All branches" or one of them (Phase 8); nothing when there is no choice. --}}
@if ($branchChoices->isNotEmpty())
    <div class="chips" style="margin-bottom:0.85rem" role="group" aria-label="{{ __('app.delay.which_branches') }}">
        <button type="button" class="chip" aria-pressed="{{ $reportBranch === '' ? 'true' : 'false' }}" wire:click="chooseBranch('')" wire:key="branch-all">
            {{ __('app.delay.all_branches') }}
        </button>
        @foreach ($branchChoices as $b)
            <button type="button" class="chip" aria-pressed="{{ $reportBranch === (string) $b->id ? 'true' : 'false' }}" wire:click="chooseBranch('{{ $b->id }}')" wire:key="branch-{{ $b->id }}">
                🏥 {{ $b->name }}
            </button>
        @endforeach
    </div>
@endif
