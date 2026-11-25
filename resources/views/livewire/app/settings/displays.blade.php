<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    <p class="muted" style="margin:0 0 0.75rem">{{ __('app.settings.displays_intro') }}</p>

    <div class="card" style="padding:1.1rem;margin-bottom:0.75rem">
        <div class="cols">
            <div class="field">
                <label for="display-name">{{ __('app.settings.display_name') }}</label>
                <input id="display-name" type="text" wire:model="name" maxlength="60" placeholder="{{ __('app.settings.display_name_hint') }}">
                @error('name') <div class="err">{{ $message }}</div> @enderror
            </div>
            @if ($branches->count() > 1)
                <div class="field">
                    <label for="display-branch">{{ __('app.booking.branch') }}</label>
                    <select id="display-branch" wire:model="branchId">
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
        <button type="button" class="btn btn-primary" wire:click="add">{{ __('app.settings.add_display') }}</button>
    </div>

    @forelse ($displays as $display)
        <div class="card" style="padding:0.9rem 1rem;margin-bottom:0.5rem" wire:key="d-{{ $display->id }}">
            <div class="cols">
                <div class="field">
                    <label for="d-name-{{ $display->id }}">{{ __('app.settings.display_name') }}</label>
                    <input id="d-name-{{ $display->id }}" type="text" maxlength="60" wire:model="edits.{{ $display->id }}.name">
                </div>
                @if ($branches->count() > 1)
                    <div class="field">
                        <label for="d-branch-{{ $display->id }}">{{ __('app.booking.branch') }}</label>
                        <select id="d-branch-{{ $display->id }}" wire:model="edits.{{ $display->id }}.branch_id">
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <div class="field">
                <label>{{ __('app.settings.display_link') }}</label>
                <div dir="ltr" style="overflow-wrap:anywhere;font-size:0.9rem">{{ $display->url() }}</div>
                <small class="muted">
                    {{ $display->last_seen_at ? __('app.settings.display_last_seen', ['time' => $display->last_seen_at->diffForHumans()]) : __('app.settings.display_never_seen') }}
                </small>
            </div>

            <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
                <button type="button" class="btn btn-sm btn-primary" wire:click="save({{ $display->id }})">{{ __('app.settings.save') }}</button>
                <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText('{{ $display->url() }}'); this.textContent='{{ __('app.actions.link_copied') }}'">{{ __('app.actions.copy_link') }}</button>
                <button type="button" class="btn btn-sm" wire:click="rotate({{ $display->id }})" wire:confirm="{{ __('app.settings.display_rotate_confirm') }}">{{ __('app.settings.display_rotate') }}</button>
                <button type="button" class="btn btn-sm btn-danger" wire:click="remove({{ $display->id }})" wire:confirm="{{ __('app.settings.display_remove_confirm') }}">{{ __('app.settings.remove') }}</button>
            </div>
        </div>
    @empty
        <div class="card" style="padding:2rem;text-align:center;color:var(--muted)">{{ __('app.settings.no_displays') }}</div>
    @endforelse
</div>
