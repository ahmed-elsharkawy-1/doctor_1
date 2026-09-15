{{--
    The platform's header on every patient-facing page: the doctor page, the
    tracking page and the review page. One partial, so the three stay identical.

    The mark and the slogan sit centred on the blue; the page's first card then
    rides up over the bottom of it. Name and slogan come from
    config('clinic.brand'), so changing them there changes every page at once.

    Not a link — the platform root is a staff sign-in, which is not somewhere
    to send a patient who taps a logo.

    @param int $overlap  how far the page's first card overlaps the blue, in px.
--}}
<style>
    .brand-header {
        /* The page's own gradient, kept literal so all three share one sky. */
        background:
            radial-gradient(120% 140% at 85% 0%, #2C7FD0 0%, transparent 55%),
            linear-gradient(200deg, #1B6BB5 0%, #124C86 55%, #0E3E6E 100%);
        background-color: #124C86;
        padding: 18px 16px calc({{ (int) ($overlap ?? 22) }}px + 16px);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        text-align: center;
    }

    .brand-header img { height: 30px; width: auto; display: block; }

    .brand-header p {
        margin: 0;
        color: rgba(255, 255, 255, .88);
        font-size: 13px;
        font-weight: 500;
        line-height: 1.4;
    }
</style>

<header class="brand-header">
    <img src="{{ asset(config('clinic.brand.logo_white')) }}"
         alt="{{ config('clinic.brand.name') }}" height="30">
    <p>{{ config('clinic.brand.slogan') }}</p>
</header>
