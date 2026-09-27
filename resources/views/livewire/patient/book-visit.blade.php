@php
    /*
     | One screen at a time. Completed steps collapse to a single line you can
     | tap to go back, which is what replaces a tab bar: the history stays
     | visible and reversible without ever showing an empty future step.
     |
     | `$stage` comes from the component and is derived from the session, never
     | from the request — see BookVisit::stage().
     */
    $clock = fn ($time) => $time === null
        ? null
        : $time->format('g:i').' '.__($time->format('A') === 'AM' ? 'booking.tracking.am' : 'booking.tracking.pm');

    $longDate = fn ($date) => $date->locale(app()->getLocale())->isoFormat('dddd D MMMM');
    $monthOf = fn ($date) => $date->locale(app()->getLocale())->isoFormat('MMMM YYYY');

    /* Same shape as the staff app's: two decimals, trailing zeros trimmed,
       and always the currency. A bare "400" on the one screen where somebody
       decides whether to pay is the worst place in the app to leave it off. */
    $money = fn ($amount): string => rtrim(rtrim(number_format((float) $amount, 2, '.', ','), '0'), '.')
        .' '.__('messages.currency');

    $onDetails = in_array($stage, ['details', 'code'], true);
    $visitType = $this->selectedVisitType();

@endphp

{{-- The day this browser is watching. The attribute changes as the patient
     moves between days, and the script in the layout re-subscribes. --}}
<div data-slots-channel="{{ \App\Events\SlotsChanged::channelFor($clinic->id, $date) }}">
    @include('partials.brand-bar', ['overlap' => 40])

    <div class="wrap">

        <div class="stack">

            {{-- Who the patient is booking with, carried through every step. --}}
            <div class="card who">

                @if ($doctor?->avatarUrl())
                    <img class="who-face" src="{{ $doctor->avatarUrl() }}" alt="{{ $doctor->name }}" loading="lazy">
                @else
                    <div class="who-face" aria-hidden="true">{{ mb_substr(preg_replace('/^د\.\s*/u', '', $doctor?->name ?? $clinic->name), 0, 1) }}</div>
                @endif

                <div style="min-width: 0">
                    <div class="who-name">{{ $doctor?->name ?? $clinic->name }}</div>
                    <div class="who-role">
                        {{ $doctor?->title ?: $clinic->specialty?->name }}
                        @if ($clinic->city) · {{ $clinic->city }} @endif
                    </div>
                </div>
            </div>

            <div class="card card-lift stack">

                @if ($stepNumber !== null)
                    <div>
                        <div class="step-note">{{ __('booking.self_booking.step_of', ['step' => $stepNumber, 'total' => $stepCount]) }}</div>
                        <div class="rail"><i style="width: {{ round($stepNumber / $stepCount * 100) }}%"></i></div>
                    </div>
                @endif

                @if ($notice !== null)
                    <div class="note {{ $failed ? 'note-bad' : 'note-ok' }}" role="status">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        <span>{{ $notice }}</span>
                    </div>
                @endif

                {{-- 1 ·  The week, read-only ------------------------------- --}}
                @if ($stage === 'overview')
                    @if ($visitTypes->isEmpty())
                        <div class="note note-plain">{{ __('booking.self_booking.nothing_bookable') }}</div>
                    @else
                        {{-- The page's heading, now that the title row above
                             the card is gone. `peek_title` said nearly the
                             same thing one line below it; one of the two had
                             to go. --}}
                        <h1 class="h1">{{ __('booking.self_booking.available_title') }}</h1>

                        <div class="note note-info">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                            <span>{{ __('booking.self_booking.peek_lead') }}</span>
                        </div>

                        @php
                            $soonest = collect($days)->first(fn ($d) => ($d['available_count'] ?? 0) > 0);
                        @endphp

                        @if ($soonest)
                            {{-- The one fact a stranger came for, said outright
                                 rather than left to be read off a table. --}}
                            <p class="soonest">
                                {{ __('booking.self_booking.soonest') }}
                                <b>{{ $longDate($soonest['date']) }}@if ($soonest['first_free']) — {{ $clock($soonest['first_free']) }}@endif</b>
                            </p>
                        @endif

                        {{-- A description list, not a list of rows in a box.
                             The old shape — bordered container, full-bleed
                             dividers, a tinted "today" row, filled pills — is
                             the shape of a menu, and on every other screen here
                             that shape means "pick one". No amount of hint text
                             wins against that, so the shape changed instead. --}}
                        <dl class="daysum">
                            @foreach ($days as $day)
                                <div class="daysum-row" wire:key="peek-{{ $day['date']->toDateString() }}">
                                    <dt>
                                        {{ $day['day']->label() }}
                                        <span class="daysum-date">{{ $day['date']->locale(app()->getLocale())->isoFormat('D MMMM') }}</span>
                                        @if ($day['is_today'])
                                            <span class="daysum-today">{{ __('booking.self_booking.today') }}</span>
                                        @endif
                                    </dt>

                                    <dd @class([
                                            'daysum-n',
                                            'is-off' => ! $day['is_open'],
                                            'is-full' => $day['is_open'] && ($day['available_count'] ?? 0) === 0,
                                        ])>
                                        @if (! $day['is_open'])
                                            {{ $day['is_holiday'] ? __('booking.self_booking.holiday') : __('booking.self_booking.closed') }}
                                        @elseif (($day['available_count'] ?? 0) === 0)
                                            {{ __('booking.self_booking.full') }}
                                        @else
                                            {{ __('booking.self_booking.slots_available', ['count' => $day['available_count']]) }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="note note-plain">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12c0 1.6.376 3.112 1.043 4.453L2 22l5.667-1.017A9.955 9.955 0 0 0 12 22Z"/></svg>
                            <span>{{ __('booking.self_booking.verify_notice') }}</span>
                        </div>
                    @endif
                @endif

                {{-- 2 ·  Name and number ---------------------------------- --}}
                @if ($stage === 'details')
                    <div>
                        <h1 class="h1">{{ __('booking.self_booking.details_title') }}</h1>
                        <p class="hint" style="margin: 6px 0 0">{{ __('booking.self_booking.details_lead') }}</p>
                    </div>

                    <div class="field">
                        <label class="label" for="name">{{ __('booking.self_booking.name_label') }}</label>
                        <input id="name" type="text" wire:model="name" autocomplete="name"
                               placeholder="{{ __('booking.self_booking.name_placeholder') }}">
                        @error('name') <div class="err">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label class="label" for="phone">{{ __('booking.self_booking.phone_label') }}</label>
                        {{-- One plain field. The clinic's own country is already
                             known, and PhoneNumber::parse takes 01…, +20… or a
                             spaced number without being told which. --}}
                        <input id="phone" type="tel" class="ltr" style="text-align: left"
                               wire:model="phone" autocomplete="tel"
                               placeholder="{{ __('booking.self_booking.phone_placeholder') }}">
                        <div class="hint">{{ __('booking.self_booking.phone_hint') }}</div>
                        @error('phone') <div class="err">{{ $message }}</div> @enderror
                    </div>

                    <div class="note note-dashed">
                        <div>
                            <div style="font-size: 12.5px; font-weight: 700; color: var(--ink-soft)">{{ __('booking.self_booking.family_title') }}</div>
                            <div class="hint" style="margin-top: 4px">{{ __('booking.self_booking.family_lead') }}</div>
                        </div>
                    </div>
                @endif

                {{-- 3 ·  The code ---------------------------------------- --}}
                @if ($stage === 'code')
                    <div>
                        <h1 class="h1">{{ __('booking.self_booking.code_title') }}</h1>
                        <p class="hint" style="margin: 6px 0 0">{{ __('booking.self_booking.code_lead', ['length' => $codeLength]) }}</p>
                    </div>

                    <div class="field">
                        <label class="label" for="code">{{ __('booking.self_booking.code_label') }}</label>

                        {{-- Boxes are drawn; the input over them is the only
                             real field. Digits are rendered server-side too,
                             so a round trip never blanks them. --}}
                        <div class="code-boxes" data-code-boxes>
                            @for ($i = 0; $i < $codeLength; $i++)
                                <div class="code-box
                                            {{ mb_strlen($code ?? '') > $i ? 'is-filled' : '' }}
                                            {{ mb_strlen($code ?? '') === $i ? 'is-next' : '' }}">{{ mb_substr($code ?? '', $i, 1) }}</div>
                            @endfor

                            <input id="code" type="text" inputmode="numeric" class="code-entry"
                                   maxlength="{{ $codeLength }}" wire:model="code"
                                   autocomplete="one-time-code"
                                   aria-label="{{ __('booking.self_booking.code_label') }}">
                        </div>

                        @error('code') <div class="err">{{ $message }}</div> @enderror
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap">
                        <span class="ltr hint">{{ $phone }}</span>
                        <button type="button" wire:click="changeNumber"
                                style="border: 0; background: none; padding: 0; font-size: 13px; font-weight: 700; color: var(--primary); cursor: pointer">
                            {{ __('booking.self_booking.change_number') }}
                        </button>
                    </div>

                    <div style="text-align: center">
                        <div class="hint">{{ __('booking.self_booking.no_code') }}</div>
                        <button type="button" wire:click="resendCode"
                                style="border: 0; background: none; padding: 4px; font-size: 13px; font-weight: 700; color: var(--primary); cursor: pointer">
                            {{ __('booking.self_booking.resend') }}
                        </button>
                        <div class="hint">{{ __('booking.self_booking.resend_after', ['seconds' => $resendSeconds]) }}</div>
                    </div>

                    <div class="note note-plain">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>{{ __('booking.self_booking.code_security', ['minutes' => $codeMinutes]) }}</span>
                    </div>
                @endif

                {{-- They already have one -------------------------------- --}}
                @if ($stage === 'upcoming' && $upcoming !== null)
                    <div style="text-align: center; padding-top: 4px">
                        <h1 class="h1">{{ __('booking.self_booking.upcoming_title') }}</h1>
                        <p class="hint" style="margin: 7px 0 0">
                            {{ __('booking.self_booking.upcoming_lead', [
                                'patient' => $upcoming->patient?->name,
                                'doctor' => $doctor?->name ?? $clinic->name,
                            ]) }}
                        </p>
                    </div>

                    <div class="card" style="display: flex; align-items: center; gap: 13px">
                        <div style="flex: 0 0 auto; width: 48px; height: 48px; border-radius: 15px; background: var(--primary-50); display: grid; place-items: center; text-align: center">
                            <div>
                                <div style="font-size: 9px; font-weight: 700; color: var(--primary)">{{ $upcoming->visit_date->locale(app()->getLocale())->isoFormat('ddd') }}</div>
                                <div style="font-size: 18px; font-weight: 800; color: var(--primary); line-height: 1">{{ $upcoming->visit_date->format('j') }}</div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 17px; font-weight: 800">{{ $clock($upcoming->start_at) ?? __('app.queue.no_time') }}</div>
                            <div class="hint">{{ $upcoming->visitType?->name }} · {{ __('booking.self_booking.minutes', ['count' => $upcoming->duration_minutes]) }}</div>
                        </div>
                    </div>

                    <p class="hint" style="text-align: center">{{ __('booking.self_booking.upcoming_change') }}</p>
                @endif

                {{-- 4 ·  Choosing the time -------------------------------- --}}
                @if ($stage === 'appointment')
                    <div>
                        <h1 class="h1">{{ __('booking.self_booking.appointment_title') }}</h1>
                        <p class="hint" style="margin: 6px 0 0">{{ __('booking.self_booking.appointment_lead') }}</p>
                    </div>

                    <button type="button" class="card" wire:click="changeNumber"
                            style="display: flex; align-items: center; gap: 10px; text-align: start; cursor: pointer">
                        <span style="flex: 0 0 auto; width: 22px; height: 22px; border-radius: 8px; background: var(--success-bg); display: grid; place-items: center">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span style="flex: 1 1 auto; min-width: 0">
                            <span style="display: block; font-size: 13.5px; font-weight: 700">{{ $name }}</span>
                            <span class="ltr hint" style="display: block">{{ $verifiedPhone }}</span>
                        </span>
                        <span style="flex: 0 0 auto; font-size: 12.5px; font-weight: 700; color: var(--primary)">{{ __('booking.self_booking.edit') }}</span>
                    </button>

                    @if ($visitTypes->count() > 1)
                        <div>
                            <div class="label" style="margin-bottom: 8px">{{ __('booking.self_booking.visit_type') }}</div>
                            <div class="pills">
                                @foreach ($visitTypes as $type)
                                    <button type="button" class="pill" wire:key="type-{{ $type->id }}"
                                            aria-pressed="{{ $visitTypeId === $type->id ? 'true' : 'false' }}"
                                            wire:click="selectVisitType({{ $type->id }})">
                                        {{ $type->name }}
                                        <small>
                                            {{ __('booking.self_booking.minutes', ['count' => $type->duration_minutes]) }}
                                            @if ($showPrice && (float) $type->price > 0) · {{ $money($type->price) }} @endif
                                        </small>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div>
                        <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 8px">
                            <span class="label">{{ __('booking.self_booking.day') }}</span>
                            @if ($days !== [])
                                <span class="month">{{ $monthOf($days[0]['date']) }}</span>
                            @endif
                        </div>

                        <div class="days">
                            @foreach ($days as $day)
                                @php $full = $day['is_open'] && ($day['available_count'] ?? 0) === 0; @endphp
                                <button type="button" wire:key="day-{{ $day['date']->toDateString() }}"
                                        @class(['day', 'is-full' => $full, 'is-off' => ! $day['is_open']])
                                        aria-pressed="{{ $date === $day['date']->toDateString() ? 'true' : 'false' }}"
                                        @disabled(! $day['is_open'] || $full)
                                        wire:click="selectDay('{{ $day['date']->toDateString() }}')">
                                    <span class="day-name">{{ $day['day']->label() }}</span>
                                    <span class="day-num">{{ $day['date']->format('j') }}</span>
                                    <span class="day-note">
                                        @if (! $day['is_open'])
                                            {{ $day['is_holiday'] ? __('booking.self_booking.holiday') : __('booking.self_booking.closed') }}
                                        @elseif ($full)
                                            {{ __('booking.self_booking.full') }}
                                        @else
                                            <span class="day-dot" aria-hidden="true"></span>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 8px">
                            <span class="label">{{ __('booking.self_booking.slot') }}</span>
                            <span class="hint">{{ __('booking.self_booking.taken_note') }}</span>
                        </div>

                        @if ($availability === null || ! $availability->isOpen || $availability->availableCount() === 0)
                            <div class="note note-plain">
                                {{ $availability?->closedReason?->label() ?? __('booking.self_booking.no_slots_day') }}
                            </div>
                        @else
                            <div class="slots">
                                @foreach ($availability->slots as $slot)
                                    <button type="button" class="slot" wire:key="slot-{{ $slot->startAt->format('Hi') }}"
                                            aria-pressed="{{ $startTime === $slot->startAt->format('H:i') ? 'true' : 'false' }}"
                                            @disabled(! $slot->isAvailable)
                                            wire:click="selectSlot('{{ $slot->startAt->format('H:i') }}')">
                                        {{ $clock($slot->startAt) }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Done ------------------------------------------------- --}}
                @if ($stage === 'done' && $confirmed !== null)
                    <div style="text-align: center; padding-top: 4px">
                        <div style="width: 74px; height: 74px; margin: 0 auto 14px; border-radius: 50%; background: var(--success-bg); display: grid; place-items: center">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <h1 class="h1">{{ __('booking.self_booking.done_title') }}</h1>
                        <p class="hint" style="margin: 8px 0 0">{{ __('booking.self_booking.done_lead') }}</p>
                    </div>

                    <div class="card" style="display: flex; align-items: center; gap: 13px">
                        <div style="flex: 0 0 auto; width: 52px; height: 52px; border-radius: 16px; background: var(--primary-50); display: grid; place-items: center; text-align: center">
                            <div>
                                <div style="font-size: 9.5px; font-weight: 700; color: var(--primary)">{{ $confirmed->visit_date->locale(app()->getLocale())->isoFormat('ddd') }}</div>
                                <div style="font-size: 20px; font-weight: 800; color: var(--primary); line-height: 1">{{ $confirmed->visit_date->format('j') }}</div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 20px; font-weight: 800">{{ $clock($confirmed->start_at) }}</div>
                            <div class="hint">{{ $longDate($confirmed->visit_date) }} · {{ $confirmed->visitType?->name }}</div>
                        </div>
                    </div>

                    <div class="stack" style="gap: 9px">
                        <div style="display: flex; justify-content: space-between">
                            <span class="hint">{{ __('booking.self_booking.done_patient') }}</span>
                            <span style="font-size: 13.5px; font-weight: 700">{{ $confirmed->patient?->name }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between">
                            <span class="hint">{{ __('booking.self_booking.done_code') }}</span>
                            <span class="ltr" style="font-size: 13.5px; font-weight: 700">{{ $confirmed->patient?->code }}</span>
                        </div>
                        @if ($clinic->address)
                            <div style="display: flex; justify-content: space-between; gap: 16px">
                                <span class="hint" style="flex: 0 0 auto">{{ __('booking.self_booking.done_location') }}</span>
                                <span style="font-size: 13.5px; font-weight: 700; text-align: end">{{ $clinic->address }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="note note-ok">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12c0 1.6.376 3.112 1.043 4.453L2 22l5.667-1.017A9.955 9.955 0 0 0 12 22Z"/></svg>
                        <span>{{ __('booking.self_booking.done_sent', ['patient' => $confirmed->patient?->name]) }}</span>
                    </div>

                    <div class="note note-info">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        <div>
                            <div style="font-size: 13.5px; font-weight: 700; color: var(--ink-soft)">{{ __('booking.self_booking.done_track_title') }}</div>
                            <div class="hint" style="margin-top: 3px">{{ __('booking.self_booking.done_track_lead') }}</div>
                        </div>
                    </div>

                    <p class="hint" style="text-align: center">{{ __('booking.self_booking.done_change') }}</p>
                @endif

            </div>
        </div>
    </div>

    {{-- The action bar. Fixed on a phone, part of the page once there is room. --}}
    <div class="bar">
        <div class="bar-inner">
            @if ($stage === 'overview')
                <button type="button" class="btn btn-primary" wire:click="start" @disabled($visitTypes->isEmpty())>
                    {{ __('booking.self_booking.start') }}
                </button>

                {{-- The way out, in the bar with everything else that acts.
                     A labelled button in the content column beats an icon on
                     the header: the header runs the full width of the screen,
                     so on a desktop its corner is nowhere near what the eye is
                     reading. --}}
                <a class="btn btn-outline" href="{{ url('/'.$clinic->slug) }}">
                    {{ __('booking.self_booking.done_back') }}
                </a>

            @elseif ($stage === 'details')
                <button type="button" class="btn btn-primary" wire:click="sendCode">
                    {{ __('booking.self_booking.send_code') }}
                </button>

                <button type="button" class="btn btn-outline" wire:click="back">
                    {{ __('booking.self_booking.previous') }}
                </button>

            @elseif ($stage === 'code')
                <button type="button" class="btn btn-primary" wire:click="verifyCode">
                    {{ __('booking.self_booking.verify') }}
                </button>
                <button type="button" class="btn btn-outline" wire:click="back">
                    {{ __('booking.self_booking.previous') }}
                </button>

            @elseif ($stage === 'upcoming' && $upcoming !== null)
                <a class="btn btn-primary" href="{{ $upcoming->trackingUrl() }}">
                    {{ __('booking.self_booking.track') }}
                </a>
                @if ($whatsappUrl)
                    <a class="btn btn-wa" href="{{ $whatsappUrl }}">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.6 14.1c-.2.6-1.2 1.2-1.7 1.2-.4 0-.9.2-3.1-.7-2.6-1.1-4.2-3.8-4.3-4-.1-.2-1-1.4-1-2.6 0-1.2.6-1.8.9-2 .2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.3.3c-.1.1-.3.3-.1.6.1.3.7 1.2 1.5 1.9 1 .9 1.8 1.2 2.1 1.3.2.1.4.1.6-.1l.8-1c.2-.2.3-.2.5-.1l2 1c.2.1.4.2.4.3.1.1.1.6-.1 1.2Z"/></svg>
                        {{ __('booking.self_booking.contact_clinic') }}
                    </a>
                @endif

            @elseif ($stage === 'appointment')
                @if ($startTime !== null && $visitType !== null)
                    {{-- The last look before committing. Laid out as labelled
                         rows rather than a single dense line: this is the only
                         screen where the patient checks what they are about to
                         book, and a date, a time and a duration run together
                         read as one string, not three facts. --}}
                    <dl class="review">
                        <div>
                            <dt>{{ __('booking.self_booking.day') }}</dt>
                            <dd>{{ $longDate(\Illuminate\Support\Carbon::parse($date, $clinic->timezone)) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('booking.self_booking.slot') }}</dt>
                            <dd>{{ $clock(\Illuminate\Support\Carbon::parse($date.' '.$startTime, $clinic->timezone)) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('booking.self_booking.expected_duration') }}</dt>
                            <dd>{{ __('booking.self_booking.minutes', ['count' => $visitType->duration_minutes]) }}</dd>
                        </div>
                        @if ($showPrice && (float) $visitType->price > 0)
                            <div>
                                <dt>{{ __('booking.self_booking.price') }}</dt>
                                <dd>{{ $money($visitType->price) }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="hold">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        <span>{{ __('booking.self_booking.held_for_you') }} · {{ __('booking.self_booking.minutes', ['count' => $holdMinutes]) }}</span>
                    </div>
                @endif

                <button type="button" class="btn btn-primary" wire:click="confirm" @disabled($startTime === null)>
                    {{ __('booking.self_booking.confirm') }}
                </button>

            @elseif ($stage === 'done' && $confirmed !== null)
                <a class="btn btn-primary" href="{{ $confirmed->trackingUrl() }}">
                    {{ __('booking.self_booking.track') }}
                </a>
                @if ($whatsappUrl)
                    <a class="btn btn-wa" href="{{ $whatsappUrl }}">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.6 14.1c-.2.6-1.2 1.2-1.7 1.2-.4 0-.9.2-3.1-.7-2.6-1.1-4.2-3.8-4.3-4-.1-.2-1-1.4-1-2.6 0-1.2.6-1.8.9-2 .2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.3.3c-.1.1-.3.3-.1.6.1.3.7 1.2 1.5 1.9 1 .9 1.8 1.2 2.1 1.3.2.1.4.1.6-.1l.8-1c.2-.2.3-.2.5-.1l2 1c.2.1.4.2.4.3.1.1.1.6-.1 1.2Z"/></svg>
                        {{ __('booking.self_booking.contact_clinic') }}
                    </a>
                @endif
                <a class="btn btn-quiet" href="{{ url('/'.$clinic->slug) }}">
                    {{ __('booking.self_booking.done_back') }}
                </a>
            @endif
        </div>
    </div>
</div>
