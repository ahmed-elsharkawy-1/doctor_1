@php
    use App\Enums\BookingKind;
    use App\Enums\BookingStatus;

    $clinic = $this->clinic();

    $clock = function ($time) {
        if ($time === null) {
            return __('app.queue.no_time');
        }

        $meridiem = $time->format('A') === 'AM'
            ? __('booking.tracking.am')
            : __('booking.tracking.pm');

        return $time->format('g:i').' '.$meridiem;
    };
@endphp

<div class="wrap">
    <style>
        .tallies { display: flex; gap: 0.5rem; margin-bottom: 0.85rem; flex-wrap: wrap; }

        .tally {
            flex: 1 1 6rem;
            padding: 0.7rem;
            text-align: center;
        }

        .tally .n { font-size: 1.4rem; font-weight: 700; }
        .tally .k { color: var(--muted); font-size: 0.8rem; }

        /* The badge on a booking the patient made themselves. Tinted rather
           than loud: it says where the booking came from, not that anything
           is wrong with it. */
        .pill-new {
            background: var(--brand-soft);
            color: var(--brand);
        }

        .queue { display: grid; gap: 0.6rem; }

        .booking { padding: 0.9rem 1rem; }

        .booking header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .booking .name { font-weight: 700; }
        .booking .meta { color: var(--muted); font-size: 0.85rem; }

        .booking .acts {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
        }

        .booking.is-terminal { opacity: 0.62; }

        .search { display: flex; gap: 0.5rem; margin-bottom: 0.85rem; }

        .search input {
            flex: 1;
            min-width: 0;
            padding: 0.7rem 0.9rem;
            border: 1px solid var(--line);
            border-radius: 0.7rem;
            background: var(--card);
            color: var(--ink);
            font: inherit;
        }

        .search input:focus { outline: 2px solid var(--brand); outline-offset: 1px; }

        .found { display: grid; gap: 0.5rem; margin-bottom: 0.85rem; }
        .found .stats { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-top: 0.5rem; }

        /* One layout, widened. On a desk monitor the cards sit two abreast
           rather than becoming a different screen. */
        @media (min-width: 48rem) {
            .queue { grid-template-columns: 1fr 1fr; }
            .tally { flex: 1; }
        }
    </style>

    {{-- The clinic name, the account and sign-out live in the app bar now. --}}
    <h1 style="font-size:1.05rem;font-weight:700;margin:0 0 12px">{{ __('app.queue.title') }}</h1>

    @if ($notice !== null)
        <div class="flash {{ $failed ? 'flash-err' : 'flash-ok' }}">{{ $notice }}</div>
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

    {{-- "دوري امتى؟" on the phone. Today's queue only, so only on today's
         list; the numbers are the patient's tracking page's own. --}}
    @if ($isToday)
        <div class="search">
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('app.queue.search.placeholder') }}"
                   aria-label="{{ __('app.queue.search.placeholder') }}">
            @if ($search !== '')
                <button type="button" class="btn btn-sm" wire:click="clearSearch">
                    {{ __('app.queue.search.clear') }}
                </button>
            @endif
        </div>

        @if ($searching)
            <div class="found">
                @forelse ($matches as $match)
                    @php($found = $match['booking'])
                    @php($position = $match['position'])
                    <div class="card booking" wire:key="found-{{ $found->id }}">
                        <header>
                            <div>
                                <div class="name">{{ $found->patient?->name }}</div>
                                <div class="meta">
                                    {{ $clock($found->start_at) }}
                                    <x-after-midnight :show="$found->startsAfterMidnight()" />
                                    &middot; {{ $found->visitType?->name }}
                                    &middot; {{ $found->patient?->code }}
                                </div>
                            </div>

                            <div style="text-align:end">
                                <span class="pill">{{ $found->status->label() }}</span>
                                @if ($found->booking_kind === BookingKind::EMERGENCY)
                                    <div style="margin-top:0.3rem">
                                        <span class="pill pill-warn">{{ $found->booking_kind->label() }}</span>
                                    </div>
                                @endif
                            </div>
                        </header>

                        <div class="stats">
                            @if ($position->isNext())
                                <span class="pill pill-new">{{ __('app.queue.search.is_next') }}</span>
                            @endif
                            <span class="pill">{{ __('app.queue.search.place') }}: {{ $position->place() }}</span>
                            <span class="pill">{{ __('app.queue.search.ahead') }}: {{ $position->ahead }}</span>
                            @if ($position->aheadEmergency > 0)
                                <span class="pill pill-warn">{{ __('app.queue.search.ahead_emergency') }}: {{ $position->aheadEmergency }}</span>
                            @endif
                            <span class="pill">{{ __('app.queue.search.behind') }}: {{ $position->behind() }}</span>
                            @if ($position->withDoctor > 0)
                                <span class="pill">{{ __('app.queue.search.with_doctor') }}: {{ $position->withDoctor }}</span>
                            @endif
                            {{-- Only when the patient's page shows an estimate too:
                                 she reads out what they are looking at. --}}
                            @if (config('clinic.tracking.show_estimate') && $position->expectedAt !== null)
                                <span class="pill">{{ __('app.queue.search.expected_at') }}: {{ $clock($position->expectedAt) }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="card empty">{{ __('app.queue.search.no_matches') }}</div>
                @endforelse
            </div>
        @endif
    @endif

    <div class="tallies">
        <div class="card tally">
            <div class="n">{{ $counts['total'] }}</div>
            <div class="k">{{ __('app.queue.total') }}</div>
        </div>
        <div class="card tally">
            <div class="n">{{ $counts['waiting'] }}</div>
            <div class="k">{{ __('app.queue.waiting') }}</div>
        </div>
        <div class="card tally">
            <div class="n">{{ $counts['done'] }}</div>
            <div class="k">{{ __('app.queue.done') }}</div>
        </div>
    </div>

    @if ($bookings->isEmpty())
        <div class="card empty">{{ __('app.queue.empty') }}</div>
    @else
        <div class="queue">
            @foreach ($bookings as $booking)
                <div class="card booking @if ($booking->status->isTerminal()) is-terminal @endif"
                     wire:key="booking-{{ $booking->id }}">
                    <header>
                        <div>
                            <div class="name">{{ $booking->patient?->name }}</div>
                            <div class="meta">
                                {{ $clock($booking->start_at) }}
                                <x-after-midnight :show="$booking->startsAfterMidnight()" />
                                &middot; {{ $booking->visitType?->name }}
                                &middot; {{ $booking->patient?->code }}
                            </div>
                        </div>

                        <div style="text-align:end">
                            <span class="pill @if ($booking->status->isTerminal() && $booking->status !== BookingStatus::DONE) pill-off @endif">
                                {{ $booking->status->label() }}
                            </span>

                            @if ($booking->booking_kind === BookingKind::EMERGENCY)
                                <div style="margin-top:0.3rem">
                                    <span class="pill pill-warn">{{ $booking->booking_kind->label() }}</span>
                                </div>
                            @endif

                            {{-- The patient booked this themselves. Shown for
                                 as long as the booking exists: it is a fact
                                 about where the booking came from, not a
                                 state anybody clears. --}}
                            @if ($booking->isSelfBooked())
                                <div style="margin-top:0.3rem">
                                    <span class="pill pill-new">{{ $booking->source->label() }}</span>
                                </div>
                            @endif

                            {{-- Kept off the public booking page. --}}
                            @if ($booking->patient?->online_booking_blocked_at !== null)
                                <div style="margin-top:0.3rem">
                                    <span class="pill pill-off">{{ __('patient.online_booking.blocked') }}</span>
                                </div>
                            @endif

                            {{-- The reminders and what the patient said back.
                                 One pill per template; re-read on every
                                 render, so a WhatsApp reply changes it
                                 without a refresh. --}}
                            @php($reminderStatuses = [
                                __('app.queue.reminder.hourly_label') => $booking->reminderStatus(),
                                __('app.queue.reminder.daily_label') => $booking->dailyReminderStatus(),
                            ])
                            <div style="margin-top:0.3rem; display:flex; gap:0.3rem; flex-wrap:wrap">
                                @foreach ($reminderStatuses as $reminderLabel => $reminderStatus)
                                    <span class="pill {{ $reminderStatus === 'failed' ? 'pill-off' : ($reminderStatus === 'sent' ? 'pill-new' : '') }}">
                                        {{ $reminderLabel }}:
                                        {{ __('app.queue.reminder.'.$reminderStatus) }}
                                    </span>
                                @endforeach
                            </div>

                            @if ($booking->patient_response !== null)
                                <div style="margin-top:0.3rem">
                                    <span class="pill {{ $booking->patient_response->value === 'cancelled' ? 'pill-warn' : 'pill-new' }}">
                                        {{ $booking->patient_response->label() }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    </header>

                    @if ($cancelling === $booking->id)
                        {{-- Cancelling asks for a reason; the reasons come from QueueService. --}}
                        <div class="acts">
                            <span class="muted">{{ __('app.actions.cancel_prompt') }}</span>
                            @foreach ($cancelReasons as $reason)
                                <button type="button" class="btn btn-sm btn-danger"
                                        wire:click="cancel({{ $booking->id }}, '{{ $reason->value }}')">
                                    {{ $reason->label() }}
                                </button>
                            @endforeach
                            <button type="button" class="btn btn-sm" wire:click="dismissCancel">
                                {{ __('app.actions.dismiss') }}
                            </button>
                        </div>
                    @else
                        <div class="acts">
                            @if ($booking->status === BookingStatus::BOOKED)
                                <button type="button" class="btn btn-sm btn-primary"
                                        wire:click="arrive({{ $booking->id }})">
                                    {{ __('app.actions.arrive') }}
                                </button>
                            @elseif ($booking->status === BookingStatus::ARRIVED)
                                <button type="button" class="btn btn-sm btn-primary"
                                        wire:click="callIn({{ $booking->id }})">
                                    {{ __('app.actions.call_in') }}
                                </button>
                            @elseif ($booking->status === BookingStatus::WITH_DOCTOR)
                                <button type="button" class="btn btn-sm btn-primary"
                                        wire:click="complete({{ $booking->id }})">
                                    {{ __('app.actions.complete') }}
                                </button>
                            @endif

                            @if ($booking->status->canAdvanceTo(BookingStatus::NO_SHOW))
                                <button type="button" class="btn btn-sm"
                                        wire:click="noShow({{ $booking->id }})">
                                    {{ __('app.actions.no_show') }}
                                </button>
                            @endif

                            @if ($booking->canBeCancelled())
                                <button type="button" class="btn btn-sm btn-danger"
                                        wire:click="confirmCancel({{ $booking->id }})">
                                    {{ __('app.actions.cancel') }}
                                </button>
                            @endif

                            @if ($booking->patient?->phone)
                                <a class="btn btn-sm" href="tel:{{ $booking->patient->phone }}">
                                    {{ __('app.actions.call') }}
                                </a>
                            @endif

                            @if (! $booking->status->isTerminal())
                                <button type="button" class="btn btn-sm"
                                        onclick="navigator.clipboard.writeText('{{ $booking->trackingUrl() }}');
                                                 this.textContent='{{ __('app.actions.link_copied') }}'">
                                    {{ __('app.actions.copy_link') }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

@if (config('broadcasting.default') === 'pusher' && filled(config('broadcasting.connections.pusher.key')))
    {{--
        Tells the secretary a patient booked themselves while she was not
        looking at this screen. The badge on the card covers the case where
        she is; this covers the case where she is not.

        Private channel, because unlike the patient page's it names a patient.
        Authorised at /broadcasting/auth against her session — see
        routes/channels.php.

        Optional throughout: with this blocked the queue is exactly as correct,
        it just waits to be reloaded.
    --}}
    <script src="https://js.pusher.com/8.4/pusher.min.js" crossorigin="anonymous"></script>
    <script>
        (function () {
            if (typeof Pusher === 'undefined' || window.__clinicChannelBound) {
                return;
            }

            window.__clinicChannelBound = true;

            var pusher = new Pusher(@json(config('broadcasting.connections.pusher.key')), {
                cluster: @json(config('broadcasting.connections.pusher.options.cluster')),
                authEndpoint: '/broadcasting/auth',
                auth: {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                },
            });

            pusher.subscribe(@json('private-clinic.'.$this->clinic()->id))
                .bind('SelfBookingReceived', function (data) {
                    if (window.Livewire) {
                        window.Livewire.dispatch('self-booking-received', { name: data.patient_name });
                    }
                })
                .bind('ReminderResponseReceived', function (data) {
                    if (window.Livewire) {
                        window.Livewire.dispatch('reminder-responded', {
                            name: data.patient_name,
                            response: data.patient_response,
                        });
                    }
                });
        })();
    </script>
@endif
</div>
