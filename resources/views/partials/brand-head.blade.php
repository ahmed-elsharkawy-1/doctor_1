{{--
    Icons and share card for every public page.

    Paths come from config('clinic.brand'), so replacing the artwork is a
    matter of dropping new files into public/images/brand — no view changes.

    @param string|null $shareImage  overrides the default cover, e.g. a doctor's
                                    own portrait on their page.
--}}
<link rel="icon" type="image/png" href="{{ asset(config('clinic.brand.favicon')) }}">
<link rel="apple-touch-icon" href="{{ asset(config('clinic.brand.apple_touch_icon')) }}">
<meta name="theme-color" content="{{ config('clinic.brand.color') }}">
<meta property="og:image" content="{{ $shareImage ?? asset(config('clinic.brand.cover')) }}">
<meta name="twitter:image" content="{{ $shareImage ?? asset(config('clinic.brand.cover')) }}">
