<x-layouts.reports :title="__('reports.page.sign_in_title')">
    <style>
        .rp-form label { display: block; font-weight: 700; margin: 0 0 6px; }
        .rp-form .rp-input {
            width: 100%; padding: 12px 14px; margin: 0 0 14px;
            border: 1px solid var(--line); border-radius: 12px;
            font: inherit; background: var(--surface-2);
        }
        .rp-error { color: var(--danger); font-size: .9rem; margin: 0 0 12px; }
    </style>

    <section class="rp-card">
        <h2>{{ __('reports.page.sign_in_title') }}</h2>
        <p class="rp-muted">{{ __('reports.page.sign_in_lead') }}</p>

        <form class="rp-form" method="POST" action="{{ route('reports.login.store') }}">
            @csrf

            @error('email')
                <p class="rp-error" role="alert">{{ $message }}</p>
            @enderror

            <label for="email">{{ __('reports.page.email') }}</label>
            {{-- dir=ltr on the input only: an address reads left to right. --}}
            <input class="rp-input" id="email" name="email" type="email" dir="ltr"
                   value="{{ old('email') }}" autocomplete="username" required autofocus>

            <label for="password">{{ __('reports.page.password') }}</label>
            <input class="rp-input" id="password" name="password" type="password" dir="ltr"
                   autocomplete="current-password" required>

            <button class="rp-btn rp-btn-primary" type="submit">{{ __('reports.page.sign_in') }}</button>
        </form>
    </section>
</x-layouts.reports>
