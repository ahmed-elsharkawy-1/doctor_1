<div class="wrap">
    @include('app.partials.settings-nav')

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    <p class="muted" style="margin:0 0 0.75rem">{{ __('app.settings.team_intro') }}</p>

    @foreach ($team as $member)
        <div class="card" style="padding:0.9rem 1rem;margin-bottom:0.5rem" wire:key="m-{{ $member->id }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap">
                <span style="min-width:0">
                    <strong>{{ $member->name }}</strong>
                    <small class="muted" dir="ltr" style="display:block;text-align:start;overflow-wrap:anywhere">{{ $member->email }}</small>
                </span>
                @if ($member->isOwner())
                    <span class="pill">{{ __('app.settings.team_doctor') }}</span>
                @endif
            </div>

            @if ($member->isOwner())
                <div class="muted" style="margin-top:0.3rem">{{ __('app.settings.team_doctor_all') }}</div>
            @else
                <fieldset style="border:0;padding:0;margin:0.7rem 0 0">
                    <legend style="font-weight:600;font-size:0.9rem;margin-bottom:0.3rem">{{ __('app.settings.team_branches') }}</legend>
                    @foreach ($branches as $branch)
                        <label style="display:flex;align-items:center;gap:0.5rem;min-height:36px">
                            <input type="checkbox" value="{{ $branch->id }}" wire:model="members.{{ $member->id }}.branches">
                            <span>{{ $branch->name }}</span>
                            @unless ($branch->is_active)
                                <small class="muted">({{ __('app.settings.branch_inactive') }})</small>
                            @endunless
                        </label>
                    @endforeach
                </fieldset>

                <fieldset style="border:0;padding:0;margin:0.6rem 0 0">
                    <legend style="font-weight:600;font-size:0.9rem;margin-bottom:0.3rem">{{ __('app.settings.team_permissions') }}</legend>
                    <div class="muted" style="font-size:0.85rem;margin-bottom:0.2rem">{{ __('app.settings.team_daily_work') }}</div>
                    @foreach ($permissionOptions as $value => $label)
                        <label style="display:flex;align-items:center;gap:0.5rem;min-height:36px">
                            <input type="checkbox" value="{{ $value }}" wire:model="members.{{ $member->id }}.permissions">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>

                <button type="button" class="btn btn-primary btn-sm" style="margin-top:0.6rem" wire:click="save({{ $member->id }})">
                    {{ __('app.settings.save') }}
                </button>
            @endif
        </div>
    @endforeach
</div>
