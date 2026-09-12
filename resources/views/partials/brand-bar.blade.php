{{--
    The platform's mark on a patient-facing page.

    Deliberately quiet: the patient's relationship is with their doctor, not
    with us, so this identifies the service and then gets out of the way. It
    fills the blue strip both pages already drew and left empty.

    Not a link — the platform root is a staff sign-in, which is not somewhere
    to send a patient who taps a logo.
--}}
<div class="topbar">
    <div class="topbar-inner">
        <img src="{{ asset(config('clinic.brand.logo_white')) }}"
             alt="{{ __('landing.brand') }}" width="47" height="22">
        <span>{{ __('landing.brand') }}</span>
    </div>
</div>
