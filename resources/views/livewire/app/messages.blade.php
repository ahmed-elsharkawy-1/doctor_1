@php
    use App\Livewire\App\Messages;

    $clock = function ($time) {
        if ($time === null) {
            return __('app.queue.no_time');
        }

        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };

    // Templates carry no display name of their own, only a key. A translated
    // label is used where one exists, and the key otherwise, so a template
    // added later still appears instead of vanishing.
    $label = function (string $key) {
        $line = 'app.messages.template.'.$key;

        return __($line) === $line ? $key : __($line);
    };
@endphp

<div class="wrap">
    <style>
        .tpl {
            display: block;
            width: 100%;
            text-align: start;
            padding: 0.85rem 1rem;
            border: 0;
            background: none;
            font: inherit;
            color: inherit;
            cursor: pointer;
        }

        .tpl + .tpl { border-top: 1px solid var(--line); }
        .tpl[aria-pressed="true"] { background: var(--brand-soft); }
        .tpl .k { font-weight: 700; }
        .tpl .b { color: var(--muted); font-size: 0.85rem; }

        .who-line {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            text-align: start;
            padding: 0.65rem 1rem;
            border: 0;
            background: none;
            font: inherit;
            color: inherit;
            cursor: pointer;
        }

        .who-line + .who-line { border-top: 1px solid var(--line); }

        .who-line .box {
            flex: 0 0 1.15rem;
            height: 1.15rem;
            border: 2px solid var(--line);
            border-radius: 0.35rem;
        }

        .who-line[aria-pressed="true"] .box { border-color: var(--brand); background: var(--brand); }
        .who-line .name { font-weight: 600; }
        .who-line .meta { color: var(--muted); font-size: 0.85rem; }
        .who-line.no-consent { opacity: 0.55; }

        .warn {
            padding: 1rem;
            border: 1px solid var(--danger);
            border-radius: 0.9rem;
            margin-bottom: 0.85rem;
        }

        .warn.mild { border-color: var(--line); }
        .warn p { margin: 0 0 0.6rem; }
    </style>

    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.messages.title') }}</h1>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
    @endif

    @if ($result !== null)
        {{-- The service's own report, not a recount. --}}
        <div class="card rep" style="padding:1rem;margin-bottom:0.6rem">
            <p style="margin:0">{{ __('app.messages.result_sent', ['count' => $result['sent_count']]) }}</p>

            @if ($result['skipped_count'] > 0)
                <p class="muted" style="margin:0.35rem 0 0">
                    {{ __('app.messages.result_skipped', ['count' => $result['skipped_count']]) }}
                </p>
            @endif

            @if ($result['cancelled_count'] > 0)
                <p style="margin:0.35rem 0 0;color:var(--danger)">
                    {{ __('app.messages.result_cancelled', ['count' => $result['cancelled_count']]) }}
                </p>
            @endif
        </div>
    @endif

    <div class="daybar">
        <button type="button" class="btn btn-sm" wire:click="goToDay(-1)">
            {{ __('app.queue.previous_day') }}
        </button>

        <div class="day">
            {{ $date }}
            <button type="button" class="btn btn-sm" wire:click="today">
                {{ __('app.queue.today') }}
            </button>
        </div>

        <button type="button" class="btn btn-sm" wire:click="goToDay(1)">
            {{ __('app.queue.next_day') }}
        </button>
    </div>

    {{-- The template --}}
    <div class="card" style="padding:0;overflow:hidden;margin-bottom:0.85rem">
        @foreach ($templates as $template)
            <button type="button" class="tpl"
                    aria-pressed="{{ $templateKey === $template->key ? 'true' : 'false' }}"
                    wire:key="tpl-{{ $template->key }}"
                    wire:click="selectTemplate('{{ $template->key }}')">
                <span class="k">{{ $label($template->key) }}</span>
                <span class="b" style="display:block">{{ $template->body_ar }}</span>
            </button>
        @endforeach
    </div>

    {{-- Who it goes to --}}
    @if ($recipients->isEmpty())
        <div class="card empty">{{ __('app.messages.nobody') }}</div>
    @else
        <p class="muted" style="margin:0 0 0.6rem">
            {{ __('app.messages.will_send', ['count' => $willSend->count()]) }}
            @if ($willSkip->isNotEmpty())
                &middot; {{ __('app.messages.will_skip', ['count' => $willSkip->count()]) }}
            @endif
        </p>

        <div class="card" style="padding:0;overflow:hidden;margin-bottom:0.85rem">
            @foreach ($recipients as $booking)
                @php $noConsent = $booking->patient?->whatsapp_opt_in_at === null; @endphp
                <button type="button"
                        class="who-line @if ($noConsent) no-consent @endif"
                        aria-pressed="{{ $selected === [] || in_array($booking->id, $selected, true) ? 'true' : 'false' }}"
                        wire:key="rcpt-{{ $booking->id }}"
                        wire:click="toggle({{ $booking->id }})">
                    <span class="box"></span>
                    <span style="flex:1">
                        <span class="name">{{ $booking->patient?->name }}</span>
                        <span class="meta" style="display:block">
                            {{ $clock($booking->start_at) }} &middot; {{ $booking->visitType?->name }}
                        </span>
                    </span>
                    @if ($noConsent)
                        <span class="pill pill-off">{{ __('app.messages.no_consent') }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($templateKey === null)
            <p class="muted">{{ __('app.messages.pick_template') }}</p>
        @elseif ($confirming)
            <div class="warn @if (! $this->cancelsTheDay()) mild @endif">
                <p><b>{{ __('app.messages.confirm', ['count' => $willSend->count()]) }}</b></p>

                @if ($this->cancelsTheDay())
                    {{-- The one template that changes data as well as sending. --}}
                    <p style="color:var(--danger)">
                        {{ __('app.messages.also_cancels', ['count' => $recipients->count()]) }}
                    </p>
                @endif

                <button type="button" class="btn btn-danger" wire:click="send">
                    {{ __('app.messages.send_now') }}
                </button>
                <button type="button" class="btn" wire:click="dismiss">
                    {{ __('app.actions.dismiss') }}
                </button>
            </div>
        @else
            <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
                <button type="button" class="btn btn-primary" wire:click="confirm">
                    {{ __('app.messages.send', ['count' => $willSend->count()]) }}
                </button>

                @if ($selected !== [])
                    <button type="button" class="btn" wire:click="selectAll">
                        {{ __('app.postpone.select_all') }}
                    </button>
                @endif
            </div>
        @endif
    @endif
</div>
