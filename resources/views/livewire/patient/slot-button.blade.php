{{--
    One slot. Extracted only so the flat list and the grouped list cannot
    drift apart — a slot must look and behave the same either way.
--}}
<button type="button" class="slot" wire:key="slot-{{ $slot->startAt->format('Hi') }}"
        aria-pressed="{{ $startTime === $slot->startAt->format('H:i') ? 'true' : 'false' }}"
        @disabled(! $slot->isAvailable)
        wire:click="selectSlot('{{ $slot->startAt->format('H:i') }}')">
    {{ $clock($slot->startAt) }}

    {{-- Picked from Monday's list, but it is Tuesday by the clock: said on
         the button, so nobody books 01:00 thinking it is Monday morning. --}}
    @unless ($slot->startAt->isSameDay($day))
        <span class="slot-next-day">
            {{ __('booking.self_booking.slot_next_day') }}
        </span>
    @endunless
</button>
