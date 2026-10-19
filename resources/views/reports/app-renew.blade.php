{{--
    Where the mobile app's WebView lands when it cannot be signed in: the link
    was spent or lapsed, or the session ended. The app watches for this
    address and asks for a new link before this page is ever drawn; the text
    is only for the case where it does not.
--}}
<x-layouts.reports :title="__('reports.app.renew_title')" :app="true">
    <section class="rp-card" style="text-align: center">
        <h1 style="font-size: 1.15rem; margin: 0 0 6px">{{ __('reports.app.renew_title') }}</h1>
        <p class="rp-muted" style="margin: 0">{{ __('reports.app.renew_body') }}</p>
    </section>
</x-layouts.reports>
