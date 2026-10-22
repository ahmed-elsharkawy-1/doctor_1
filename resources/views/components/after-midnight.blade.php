{{-- "بعد منتصف الليل" beside a time that belongs to the evening before:
     01:00 in Monday's 19:00–03:00 is Monday's, and must not read as Monday
     morning. Renders nothing unless `show` is true. --}}
@props(['show' => true])

@if ($show)
    <span {{ $attributes->class('after-midnight') }}>{{ __('schedule.after_midnight') }}</span>
@endif
