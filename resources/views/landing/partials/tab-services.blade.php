<section class="cols panel" id="panel-services" role="tabpanel" aria-labelledby="tab-services" hidden>
    <div class="stack">
        @if ($visitTypes->isNotEmpty())
            <section class="card">
                <h2>{{ __('landing.services') }}</h2>

                <div class="service-grid">
                    @foreach ($visitTypes as $visitType)
                        <div class="service-block"><b>{{ $visitType->name }}</b></div>
                    @endforeach
                </div>

                <p class="hint">
                    @include('landing.partials.icon', ['name' => 'info'])
                    {{ __('landing.services_note') }}
                </p>
            </section>
        @endif

        <section class="card">
            <h2>{{ __('landing.hours') }}</h2>

            @foreach ($clinic->schedules as $schedule)
                @php $isToday = $schedule->day_of_week === $todayDayOfWeek; @endphp
                <div class="hour @if ($isToday) is-today @endif">
                    <span class="day">
                        {{ $schedule->day_of_week->label() }}
                        @if ($isToday)
                            <span class="today-pill">{{ __('landing.today') }}</span>
                        @endif
                    </span>

                    @if ($schedule->is_open && $schedule->periods->isNotEmpty())
                        {{-- A tag each: a split day reads as two shifts, not one
                             comma-separated string. --}}
                        <span class="times">
                            @foreach ($schedule->periods as $period)
                                <bdi class="time-tag">{{ $period->readableRange() }}</bdi>
                            @endforeach
                        </span>
                    @else
                        <span class="off">{{ __('landing.closed') }}</span>
                    @endif
                </div>
            @endforeach

            <p class="hint">
                @include('landing.partials.icon', ['name' => 'info'])
                {{ __('landing.hours_note') }}
            </p>
        </section>
    </div>

    @include('landing.partials.booking-card', [
        'heading' => __('landing.book_title'),
        'lead' => __('landing.book_lead'),
    ])
</section>
