<x-layouts.app :title="__('app.login.title')">
    <style>
        .login-wrap {
            max-width: 24rem;
            margin: 0 auto;
            padding: 3rem 1rem;
        }

        .login-wrap h1 { margin: 0 0 0.2rem; font-size: 1.3rem; }
        .login-wrap .lead { color: var(--muted); margin: 0 0 1.5rem; }

        .field { margin-bottom: 1rem; }
        .field label { display: block; font-weight: 600; margin-bottom: 0.35rem; }

        .field input[type="email"],
        .field input[type="password"] {
            width: 100%;
            padding: 0.7rem 0.85rem;
            border: 1px solid var(--line);
            border-radius: 0.6rem;
            background: var(--card);
            color: var(--ink);
            font: inherit;
        }

        .field input:focus { outline: 2px solid var(--brand); outline-offset: 1px; }

        .check { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; }
        .errors { margin-bottom: 1rem; }
    </style>

    <div class="login-wrap">
        <h1>{{ __('app.login.title') }}</h1>
        <p class="lead">{{ __('app.login.lead') }}</p>

        @if ($errors->any())
            <div class="flash flash-err errors">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('app.login.store') }}">
            @csrf

            <div class="field">
                <label for="email">{{ __('app.login.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username" dir="ltr">
            </div>

            <div class="field">
                <label for="password">{{ __('app.login.password') }}</label>
                <input id="password" name="password" type="password"
                       required autocomplete="current-password" dir="ltr">
            </div>

            <label class="check">
                <input type="checkbox" name="remember" value="1">
                <span>{{ __('app.login.remember') }}</span>
            </label>

            <button type="submit" class="btn btn-primary" style="width:100%">
                {{ __('app.login.submit') }}
            </button>
        </form>
    </div>
</x-layouts.app>
