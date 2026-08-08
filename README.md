# Doctor 1 — Clinic Booking, Queue & Insights

Backend for a clinic booking and queue system. One Laravel application serving
two surfaces:

- **Mobile API** (`/api/v1`) — the whole clinic: bookings, queue, patients,
  settings and reports. Used by the doctor and the secretary.
- **Filament panel** (`/admin`) — the **platform operator only**: creating
  clinics, doctors and staff accounts. No clinic ever signs in here.

Patients never sign in. See the full specification for scope and rules:
`../../../clinic-booking-system/SPEC-v1.md`.

## Stack

PHP 8.3 · Laravel 12 · Filament 4 · Sanctum · MySQL (SQLite for tests)

## Getting started

```bash
composer install
cp .env.example .env          # then set DB_DATABASE to an empty schema
php artisan key:generate
php artisan migrate --seed    # specialties + super admin
php artisan db:seed --class=DemoClinicSeeder
php artisan serve
```

`DemoClinicSeeder` builds a working clinic to develop and demo against: priced
visit types, a real week of hours, a holiday, 16 patients with five months of
history, today's queue in every state, and two patients awaiting rebooking.
It is safe to re-run — it rebuilds the demo clinic each time.

Today's queue is created through the **real** `BookingService`, so seeding also
exercises slot availability, the write lock, patient codes and the price
snapshot against your actual database.

| Login | Password |
|---|---|
| `doctor@doctor1.test` (owner) | `password` |
| `nour@doctor1.test` (secretary) | `password` |
| `admin@doctor1.test` (super admin — **the only panel login**) | `password` |

## Layout

Mirrors the 800advance codebase so the two feel like one house style.

```
app/
  Actions/          single-purpose write operations (ProvisionClinicAction)
  DTOs/V1/          typed input crossing layer boundaries
  Enums/            backed enums — no magic strings anywhere
  Exceptions/       ApiException: renders itself as the error envelope
  Filament/Admin/   panel resources (platform operator only)
  Http/
    Controllers/Api/V1/   single-action (__invoke) controllers
    Middleware/           SetApiLocale, ResolveClinic
    Requests/Api/V1/      Form Requests
  Models/
  Services/V1/            business logic (API)
  Services/Reports/       revenue and retention maths
  Services/Results/V1/    response payload shaping
  Support/                ApiResponse, Wire, PhoneNumber
config/clinic.php    every system default lives here
lang/{ar,en}/        all user-facing strings
```

## Conventions

**Nothing configurable is hardcoded.** System defaults live in
`config/clinic.php`, per-clinic values are columns on `clinics`, and fixed sets
are backed enums.

**One response envelope.** Every API response — including framework errors — is
built by `App\Support\ApiResponse`:

```json
{ "status": "success", "message": "...", "data": {} }
{ "status": "error",   "message": "...", "error": { "code": "SLOT_UNAVAILABLE", "details": null, "fields": null } }
```

The Flutter app branches on `error.code` (a backed enum value), never on
`message`. New codes are added to `ApiErrorCode` / `AuthErrorCode`, never
invented inline.

**`value` + `display` pairs.** Anything rendered as text ships pre-formatted via
`App\Support\Wire`, driven by the `Accept-Language` header, so the client never
re-implements Arabic formatting.

**Clinic scope comes from the token.** The `clinic` middleware resolves it; no
endpoint accepts `clinic_id` from the client.

**Records are deactivated, never deleted.** Bookings reference visit types,
doctors and clinics forever, and `price` / `duration_minutes` are snapshotted
onto each booking so history cannot be rewritten.

## Commands

```bash
php artisan test                       # full suite (SQLite, in-memory)
php artisan test -c phpunit.mysql.xml  # same suite against MySQL
vendor/bin/pint                        # code style
```

**The suite drops every table in the database it is given.** `phpunit.xml`
pins it to in-memory SQLite with `force="true"`, so it cannot inherit `DB_*`
from your shell or `.env` and wipe a real database. The MySQL run is a separate
config pointing at a dedicated `prac_doctor_app_test` schema — create it first:

```sql
CREATE DATABASE prac_doctor_app_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run both before shipping: SQLite is fast, MySQL is what production uses, and
they have already disagreed once (a `date` cast that stored a time component on
one and not the other).

## Status

- **Phase 0** ✅ schema, auth, roles, admin panel, API skeleton
- **Phase 1** ✅ clinic settings API — visit types, weekly hours, holidays, bootstrap
- **Phase 2** ✅ booking API — patients, ID codes, slot availability, new booking
- **Phase 3** ✅ queue API — arrival ordering, status transitions, postpone + call list, end-of-day job
- **Phase 4** ✅ patient search and visit history — **the mobile API is complete**
- **Phase 5** ✅ revenue and retention — served by the API, owner only

**v1 is feature-complete.** Remaining before launch: pricing each clinic's visit
types, and mobile designs for the call list and patient history detail.

The API contract for the Flutter team lives in [docs/api/v1/README.md](docs/api/v1/README.md),
with the machine-readable spec in [openapi.yaml](docs/api/v1/openapi.yaml) and a
generated Postman collection alongside it (`php artisan api:postman`).
