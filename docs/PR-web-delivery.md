# Web delivery: doctor landing page, clinic web app, patient tracking

Publishing a mobile app turned out to be the hard part, so the clinic client is
now a website. **The API is unchanged and still shipped** — this adds a second
façade over the same domain layer rather than replacing it.

## What this adds

| Path | Who | Auth |
|---|---|---|
| `/{slug}` | Anyone | None — the only indexable page in the system |
| `/app` | Clinic staff | Session, one shared clinic account |
| `/b/{token}` | One patient | The token is the whole secret |
| `/` | — | Signpost to clinic sign-in |

```
Patient asks on WhatsApp from the doctor's landing page
  → secretary books them in /app          BookingService
  → a confirmation goes out with a link   WhatsAppMessagingService
  → patient watches /b/{token}            QueuePositionService
  → secretary taps statuses in /app       BookingStatusService
     ↳ the patient's waiting number moves
```

## The architectural rule

**One domain layer, two façades.** The web app calls the same services as the
API. It does not call the API over HTTP, and it re-implements nothing.
Components orchestrate and render; every business rule stays in a service. A
test asserts the payoff directly: status taps in Livewire move the patient's
counter, because both sides call the same code.

The only genuinely new domain logic is `QueuePositionService`, and even that
delegates ordering to the existing `QueueService`, so the patient and the
secretary can never see two different queues.

## Is the mobile app affected?

**No.** Verified by running `main`'s entire API test suite, unmodified, against
this branch's code: **222 passed (864 assertions)**.

The only behaviour change to an API response is `GET /message-templates`, which
now filters on `is_broadcast`. That **restores** the previous result of three
templates — the flag exists so `booking_confirmed` cannot appear on the
broadcast screen, where a secretary could have sent a whole day someone else's
confirmation and someone else's tracking link.

Everything else touching API files is import ordering, or the extraction of
`AuthService::authenticate` so the web form reuses the credential rules rather
than restating them. Behaviour identical, proven by the existing auth tests.

## ⚠️ Deploy order matters

Migrations **must** run before the new code serves traffic. Tested against a
database at `main`'s exact schema:

| | Code deployed, migrations not yet run |
|---|---|
| `POST /bookings` | **BREAKS** — `Unknown column 'tracking_token'` |
| `GET /message-templates` | **BREAKS** — `Unknown column 'is_broadcast'` |
| Everything else | OK |

```bash
php artisan migrate --force     # before traffic reaches the new code
php artisan config:cache
```

### Migration safety

A legacy booking seeded at `main`'s schema and migrated forward kept its price,
status and duration exactly; `booking_kind` defaulted to `normal` and the
tracking token was backfilled. All four migrations have working `down()`
methods and were rolled back and forward repeatedly during testing.

Two disk-level changes worth naming:

- **`is_overbooked` is dropped** from `bookings`. Nothing reads it; the values
  are discarded.
- **`tracking_token` is backfilled row by row** in chunks of 500 — slow on a
  very large table, not dangerous.

One migration, `align_bookings_with_emergency_flow`, exists because the
`create_bookings` migration had been edited in place while an environment was
already deployed. A database migrated before that work is missing
`booking_kind`, `patient_location` and `queue_entered_at`, which every booking
read needs. Every step is guarded, so it is a no-op on an up-to-date database.

## Defects found and fixed while reviewing this branch

- A platform operator opening `/app` was **signed out of Filament** — the two
  share the `web` guard. Now redirected to the panel, session untouched.
- A junk `?date=` in the query string returned a **500**. Both screens now fall
  back to today.
- `selectPatient` let `ApiException` escape, which would have rendered a JSON
  envelope into the middle of a Livewire response.
- Blade's `@error` directive unsets `$message` in view scope, which collided
  with a component property of the same name.

Each has a test that fails without its fix.

## Still outstanding

- Meta approval for the four WhatsApp templates — `docs/whatsapp/templates.md`.
  The app runs against a `log` driver until then, so nothing waits on it.
- A decision recorded in that file: Meta prefers a dynamic URL button over a
  link inlined in the body. Choosing the button needs the token passed as a
  button parameter — a small change here.
- A real slug per clinic. Backfilled slugs are transliterated and unlovely.

## Testing this branch

```bash
php artisan migrate
php artisan clinic:seed-web-demo --fresh
```

Prints every link to open — landing page, clinic app, panel, and one tracking
link per patient, with today's queue filled with a patient in each status.

---

```
391 tests passing on SQLite and MySQL
Pint clean
```

🤖 Generated with [Claude Code](https://claude.com/claude-code)
