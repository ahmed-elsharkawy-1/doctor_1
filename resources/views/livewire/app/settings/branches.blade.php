<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($editing !== null)
        <div class="card" style="padding:1.1rem;margin-bottom:0.75rem">
            <h2 style="margin:0 0 0.8rem;font-size:1.05rem">
                {{ $editing === 0 ? __('app.settings.add_branch') : __('app.settings.edit_branch') }}
            </h2>

            <div class="field">
                <label for="branch-name">{{ __('app.settings.branch_name') }}</label>
                <input id="branch-name" type="text" wire:model="name" maxlength="100">
                <div class="muted" style="font-size:0.85rem">{{ __('app.settings.branch_name_hint') }}</div>
                @error('name') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="branch-address">{{ __('app.settings.branch_address') }}</label>
                <input id="branch-address" type="text" wire:model="address" maxlength="255">
                @error('address') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="branch-map-link">{{ __('app.settings.branch_map_link') }}</label>
                <input id="branch-map-link" type="url" dir="ltr" wire:model="map_link" placeholder="https://www.google.com/maps/…">
                <div class="muted" style="font-size:0.85rem">{{ __('app.settings.branch_map_link_hint') }}</div>
                @error('map_link') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="cols">
                <div class="field">
                    <label for="branch-lat">{{ __('app.settings.branch_latitude') }}</label>
                    <input id="branch-lat" type="text" inputmode="decimal" dir="ltr" wire:model="latitude">
                    @error('latitude') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="branch-lng">{{ __('app.settings.branch_longitude') }}</label>
                    <input id="branch-lng" type="text" inputmode="decimal" dir="ltr" wire:model="longitude">
                    @error('longitude') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label for="branch-phone">{{ __('app.settings.branch_phone') }}</label>
                <input id="branch-phone" type="tel" dir="ltr" wire:model="phone">
                @if ($mainPhone)
                    <div class="muted" style="font-size:0.85rem">{{ __('app.settings.branch_phone_hint', ['phone' => $mainPhone]) }}</div>
                @endif
                @error('phone') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                <button type="button" class="btn btn-primary" wire:click="save">{{ __('app.settings.save') }}</button>
                <button type="button" class="btn" wire:click="cancelForm">{{ __('app.settings.cancel') }}</button>
            </div>
        </div>
    @elseif ($canAdd)
        <button type="button" class="btn btn-primary" style="margin-bottom:0.75rem" wire:click="startAdding">
            {{ __('app.settings.add_branch') }}
        </button>
    @endif

    @foreach ($branches as $branch)
        <div class="card" style="padding:0.85rem 1rem;margin-bottom:0.5rem" wire:key="b-{{ $branch->id }}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.75rem;flex-wrap:wrap">
                <div style="min-width:0">
                    <strong>{{ $branch->name }}</strong>
                    <span class="pill {{ $branch->is_active ? '' : 'pill-off' }}" style="margin-inline-start:0.4rem">
                        {{ $branch->is_active ? __('app.settings.branch_active') : __('app.settings.branch_inactive') }}
                    </span>
                    <div class="muted" style="margin-top:0.25rem">{{ $branch->displayAddress() }}</div>
                    <div class="muted" dir="ltr" style="text-align:start">
                        {{ $branch->contactPhone()?->national() }}
                        @unless (filled($branch->phone))
                            <small>({{ __('app.settings.branch_uses_main_phone') }})</small>
                        @endunless
                    </div>
                </div>

                <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-sm" wire:click="startEditing({{ $branch->id }})">
                        {{ __('app.settings.edit') }}
                    </button>
                    @if ($branch->is_active)
                        @if ($branches->where('is_active', true)->count() > 1)
                            <button type="button" class="btn btn-sm btn-danger" wire:click="askToDeactivate({{ $branch->id }})">
                                {{ __('app.settings.branch_deactivate') }}
                            </button>
                        @endif
                    @else
                        <button type="button" class="btn btn-sm" wire:click="activate({{ $branch->id }})">
                            {{ __('app.settings.branch_activate') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- The branch's own waiting list, under the clinic's (ELA-21). --}}
            @if ($branchListsEditable)
                <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.6rem;min-height:44px">
                    <input type="checkbox" @checked($branch->waiting_list_enabled) wire:click.prevent="toggleWaitingList({{ $branch->id }}, {{ $branch->waiting_list_enabled ? 'false' : 'true' }})">
                    <span>{{ __('app.settings.branch_waiting_list') }}</span>
                </label>

                @if ($confirmingListOff === $branch->id)
                    <div class="flash flash-warn" style="margin:0.4rem 0 0;font-weight:400">
                        {{ trans_choice('settings.branch.waiting_list_has_patients', $listOffWaiting, ['count' => $listOffWaiting]) }}
                        <div style="margin-top:0.6rem;display:flex;gap:0.4rem;flex-wrap:wrap">
                            <button type="button" class="btn btn-sm btn-danger-solid" wire:click="confirmWaitingListOff">
                                {{ __('app.settings.branch_waiting_list_off_yes') }}
                            </button>
                            <button type="button" class="btn btn-sm" wire:click="$set('confirmingListOff', null)">
                                {{ __('app.settings.cancel') }}
                            </button>
                        </div>
                    </div>
                @endif
            @endif

            @if ($confirmingDeactivate === $branch->id)
                <div class="flash flash-err" style="margin:0.75rem 0 0;font-weight:400">
                    {{ __('app.settings.branch_deactivate_confirm') }}
                    <div style="margin-top:0.6rem;display:flex;gap:0.4rem;flex-wrap:wrap">
                        <button type="button" class="btn btn-sm btn-danger-solid" wire:click="deactivate">
                            {{ __('app.settings.branch_deactivate_yes') }}
                        </button>
                        <button type="button" class="btn btn-sm" wire:click="$set('confirmingDeactivate', null)">
                            {{ __('app.settings.cancel') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
