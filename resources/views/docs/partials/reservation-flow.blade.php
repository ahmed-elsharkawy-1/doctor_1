{{-- The web reservation flow, with links that resolve against live data. --}}

<article class="card wide">
    <h2>Reservation Flow</h2>
    <div class="flow">
        <div class="step">
            <div>
                <div class="who">Patient</div>
                <p>
                    Finds the doctor's page and taps <strong>احجز عبر واتساب</strong>.
                    WhatsApp opens with the first message already written.
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">Secretary — /app</div>
                <p>
                    Takes the booking: patient, visit type, normal or emergency, day, slot.
                    Price and duration are frozen onto the booking at this moment.
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">System</div>
                <p>
                    Generates an unguessable tracking token and queues a WhatsApp
                    confirmation carrying the link.
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">Patient — /{{ config('clinic.tracking.path') }}/{token}</div>
                <p>
                    Before the day: date and time only. On the day: how many people are
                    ahead, and when to be at the clinic.
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">Secretary — /app</div>
                <p>
                    Taps <code>وصل</code> → <code>دخول الكشف</code> → <code>إنهاء الكشف</code>.
                    Each tap moves the patient's number. <strong>The counter is only ever as
                    truthful as these taps.</strong>
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">System</div>
                <p>
                    On <code>إنهاء الكشف</code>, queues a second WhatsApp message
                    inviting a rating. The link rides on the template's button,
                    not in the text.
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">Patient — /{{ config('clinic.review.path') }}/{token}</div>
                <p>
                    Same token as the tracking page. Three ratings and an optional
                    note; submitted once and never overwritten.
                </p>
            </div>
        </div>
        <div class="step">
            <div>
                <div class="who">Dashboard</div>
                <p>
                    The review lands under <a href="{{ $reviewsUrl }}">Reviews</a> with
                    its clinic, doctor and patient attached.
                </p>
            </div>
        </div>
    </div>
</article>

<article class="card">
    <h2>Web Surfaces</h2>
    <div class="rows">
        <div class="row">
            <div class="label">Doctor page</div>
            <div>
                @if ($landingUrl)
                    <a href="{{ $landingUrl }}">{{ $landingUrl }}</a>
                @else
                    <code>no demo clinic seeded</code>
                @endif
            </div>
        </div>
        <div class="row">
            <div class="label">Clinic app</div>
            <div><a href="{{ $clinicAppUrl }}">{{ $clinicAppUrl }}</a></div>
        </div>
        <div class="row">
            <div class="label">Patient page</div>
            <div><code>{{ url(config('clinic.tracking.path')) }}/{token}</code></div>
        </div>
        <div class="row">
            <div class="label">Review page</div>
            <div><code>{{ url(config('clinic.review.path')) }}/{token}</code></div>
        </div>
        <div class="row">
            <div class="label">Reviews</div>
            <div><a href="{{ $reviewsUrl }}">{{ $reviewsUrl }}</a></div>
        </div>
        <div class="row">
            <div class="label">Dashboard</div>
            <div><a href="{{ $adminUrl }}">{{ $adminUrl }}</a></div>
        </div>
    </div>
</article>

@if ($pilotClinic)
    <article class="card">
        <h2>Pilot Clinic — live flow</h2>
        <p class="subhead">
            The clinic the end-to-end flow is exercised on. Its bookings are not
            listed here: it takes real patients.
        </p>
        <div class="rows">
            <div class="row">
                <div class="label">Clinic</div>
                <div><code class="rtl">{{ $pilotClinic->name }}</code></div>
            </div>
            <div class="row">
                <div class="label">Doctor page</div>
                <div>
                    @if ($pilotLandingUrl)
                        <a href="{{ $pilotLandingUrl }}">{{ $pilotLandingUrl }}</a>
                    @else
                        <code>no slug set</code>
                    @endif
                </div>
            </div>
            <div class="row">
                <div class="label">Email</div>
                <div><code>{{ $pilotEmail }}</code></div>
            </div>
            <div class="row">
                <div class="label">Password</div>
                {{-- A real clinic's login never goes on a public page. --}}
                <div>shared privately by the team</div>
            </div>
            <div class="row">
                <div class="label">WhatsApp</div>
                <div>{{ $pilotClinic->sendsWhatsApp() ? 'WhatsApp on — patients receive messages' : 'WhatsApp switched off — nothing is sent to patients' }}</div>
            </div>
            <div class="row">
                <div class="label">Signs in to</div>
                <div>The mobile app and <a href="{{ $clinicAppUrl }}">/app</a>, same account</div>
            </div>
        </div>
    </article>
@endif

<article class="card">
    <h2>Demo Clinic</h2>
    <div class="rows">
        <div class="row">
            <div class="label">Clinic</div>
            <div><code class="rtl">{{ $clinic?->name ?? '—' }}</code></div>
        </div>
        <div class="row">
            <div class="label">Doctor</div>
            <div><code>{{ $demoEmail }}</code></div>
        </div>
        <div class="row">
            <div class="label">Assistant</div>
            <div><code>{{ $demoAssistant }}</code></div>
        </div>
        <div class="row">
            <div class="label">Password</div>
            <div><code>password</code> (a mock clinic — no real patients)</div>
        </div>
    </div>
</article>

<article class="card wide">
    <h2>Doctor's reports</h2>
    <p class="subhead">
        A day, week or month at a glance for the doctor, opened from a WhatsApp
        message each morning. Built for a web view in the app later.
    </p>
    <div class="rows">
        <div class="row">
            <div class="label">Page</div>
            <div><a href="{{ $reportsUrl }}">{{ $reportsUrl }}</a></div>
        </div>
        <div class="row">
            <div class="label">Who signs in</div>
            <div>Clinic accounts with "can see clinic reports" switched on, of clinics with the report switched on — both set in the admin panel. Same email and password as the app; remembered for 90 days.</div>
        </div>
        <div class="row">
            <div class="label">Shows</div>
            <div>Visits and income with a comparison, the next day's bookings, outcomes and no-show rate, visit types, new and returning patients, day by day (week) or week by week (month).</div>
        </div>
        <div class="row">
            <div class="label">Morning message</div>
            <div>09:00 Cairo: last month on the 1st, last week on Saturday, yesterday otherwise — one message, with a button to the page. Waiting on Meta to approve the <code>clinic_report</code> template.</div>
        </div>
    </div>
</article>

<article class="card wide">
    <h2>Today's Queue — Live</h2>

    @if ($todaysBookings->isEmpty())
        <p class="subhead">
            Nothing booked today. Run
            <code>php artisan clinic:seed-web-demo --fresh</code>
            to fill the day and get a link per patient.
        </p>
    @else
        <table class="queue-table">
            <thead>
            <tr>
                <th>Time</th>
                <th>Patient</th>
                <th>Status</th>
                <th>Patient page</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($todaysBookings as $booking)
                <tr>
                    <td><code>{{ $booking->start_at?->format('H:i') ?? '—' }}</code></td>
                    <td class="rtl">{{ $booking->patient?->name }}</td>
                    <td><code>{{ $booking->status->value }}</code></td>
                    <td><a href="{{ $booking->trackingUrl() }}">open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <p class="subhead" style="margin-top:14px">
            Open one of these beside <a href="{{ $clinicAppUrl }}">the clinic app</a>, change a
            status, and refresh the patient page — the number moves.
        </p>
    @endif
</article>
