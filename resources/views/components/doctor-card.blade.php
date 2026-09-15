{{--
    Who the patient is seeing — the first card on the doctor page and on the
    tracking page. One component, so the two stay identical.

    The tracking page passes :facts="false": a patient who has already booked
    needs to know which doctor, not the working days.

    Photo at the top right with the name and specialty beside it, then two
    boxes: working days, and the location — the whole box opens Google Maps,
    with no link text of its own, so the two boxes stay the same height. Anything
    passed in the slot (the doctor page's booking buttons) goes underneath.

    It overlaps the blue header by 40px; the pages include the brand header
    with the same overlap.
--}}
@props(['clinic', 'doctor', 'facts' => true])

@php
    $name = $doctor?->name ?? $clinic->name;
    $role = $doctor?->title ?: $clinic->specialty?->name;
    $initial = mb_substr(trim(preg_replace('/^د\.\s*/u', '', $name)), 0, 1);
    $workingDays = $facts ? $clinic->workingDaysLabel() : null;
    $location = $facts ? ($clinic->city ?: $clinic->address) : null;
    $mapLink = $clinic->mapLink();
@endphp

<style>
    .dcard {
        position: relative;
        margin-top: -40px;
        margin-bottom: 12px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 14px 28px rgba(20, 60, 100, .10);
        padding: 20px;
        color: #132433;
    }

    .dcard-top { display: flex; align-items: center; gap: 16px; }

    .dcard-photo {
        flex: 0 0 auto;
        width: 120px; height: 120px;
        border-radius: 20px;
        object-fit: cover;
        background: #EEF4FB;
    }

    .dcard-initial {
        display: grid;
        place-items: center;
        color: #185FA5;
        font-size: 44px;
        font-weight: 800;
    }

    .dcard-who { min-width: 0; }
    .dcard h1 { margin: 0; font-size: 26px; font-weight: 800; line-height: 1.25; }
    .dcard-role { margin: 6px 0 0; color: #185FA5; font-size: 16px; font-weight: 700; line-height: 1.45; }

    .dcard-facts {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 18px;
    }

    .dcard-fact {
        display: block;
        min-width: 0;
        background: #F6F9FC;
        border: 1px solid #E5ECF3;
        border-radius: 12px;
        padding: 10px 12px;
        color: inherit;
        text-decoration: none;
    }

    a.dcard-fact:hover { border-color: #CFDAE6; }

    .dcard-k { display: flex; align-items: center; gap: 6px; color: #8B9AAA; font-size: 12px; }
    .dcard-k svg { flex: 0 0 auto; color: #185FA5; }
    .dcard-v { display: block; margin-top: 2px; font-weight: 700; font-size: 14px; }

    @media (max-width: 900px) {
        .dcard { padding: 16px; }
        .dcard-top { gap: 12px; }
        .dcard h1 { font-size: 22px; }
    }
</style>

<section {{ $attributes->merge(['class' => 'dcard']) }}>
    <div class="dcard-top">
        @if ($doctor?->avatarUrl())
            <img class="dcard-photo" src="{{ $doctor->avatarUrl() }}" alt="{{ $name }}" loading="lazy">
        @else
            <div class="dcard-photo dcard-initial" aria-hidden="true">{{ $initial }}</div>
        @endif

        <div class="dcard-who">
            <h1>{{ $name }}</h1>
            @if ($role)
                <p class="dcard-role">{{ $role }}</p>
            @endif
        </div>
    </div>

    @if ($facts && ($workingDays || $location))
        <div class="dcard-facts">
            @if ($workingDays)
                <div class="dcard-fact">
                    <span class="dcard-k">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
                        {{ __('landing.stat_working_days') }}
                    </span>
                    <span class="dcard-v">{{ $workingDays }}</span>
                </div>
            @endif

            @if ($location)
                <{{ $mapLink ? 'a' : 'div' }} class="dcard-fact"
                    @if ($mapLink) href="{{ $mapLink }}" target="_blank" rel="noopener" @endif>
                    <span class="dcard-k">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        {{ __('landing.stat_location') }}
                    </span>
                    <span class="dcard-v">{{ $location }}</span>
                </{{ $mapLink ? 'a' : 'div' }}>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
