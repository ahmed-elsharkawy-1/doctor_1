{{--
    One slot. Extracted only so the flat list and the grouped list cannot
    drift apart — a slot must look and behave the same either way.
--}}
<button type="button" class="slot" wire:key="slot-{{ $slot->startAt->format('Hi') }}"
        aria-pressed="{{ $startTime === $slot->startAt->format('H:i') ? 'true' : 'false' }}"
        @disabled(! $slot->isAvailable)
        wire:click="selectSlot('{{ $slot->startAt->format('H:i') }}')">
    {{ $clock($slot->startAt) }}
</button>
