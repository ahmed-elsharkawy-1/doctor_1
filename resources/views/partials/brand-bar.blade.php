{{--
    The platform's header on every patient-facing page: the doctor page, the
    tracking page, the review page and the booking flow. One partial, so they
    stay identical.

    The mark and the slogan sit centred on the blue, and the page begins below
    it with clear air between — the first card no longer rides up over the
    bottom of the band. Name and slogan come from config('clinic.brand'), so
    changing them there changes every page at once.

    The mark is deliberately larger than the band needs. With nothing lapping
    over it the header has to carry itself, and a small logo on a wide blue
    band reads as empty space rather than as a masthead.

    Not a link — the platform root is a staff sign-in, which is not somewhere
    to send a patient who taps a logo.
--}}
<style>
    .brand-header {
        /* The page's own gradient, kept literal so all of them share one sky. */
        background:
            radial-gradient(120% 140% at 85% 0%, #2C7FD0 0%, transparent 55%),
            linear-gradient(200deg, #1B6BB5 0%, #124C86 55%, #0E3E6E 100%);
        background-color: #124C86;
        /* Balanced top and bottom. The old bottom padding existed only to make
           room for a card lapping into it, and with that gone it was height
           doing nothing. */
        padding: 20px 16px 22px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        text-align: center;
    }

    .brand-header img { height: 38px; width: auto; display: block; }

    .brand-header p {
        margin: 0;
        color: rgba(255, 255, 255, .9);
        font-size: 14px;
        font-weight: 500;
        line-height: 1.4;
    }

    /* The air between the header and whatever the page starts with. One rule
       here rather than four in four stylesheets, so the pages cannot drift. */
    .brand-header + * { margin-top: 18px; }

    @media (max-width: 380px) {
        .brand-header img { height: 34px; }
        .brand-header p { font-size: 13px; }
    }
</style>

<header class="brand-header">
    <img src="{{ asset(config('clinic.brand.logo_white')) }}"
         alt="{{ config('clinic.brand.name') }}" height="38">
    <p>{{ config('clinic.brand.slogan') }}</p>
</header>
