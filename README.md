# Doctor 1 — Clinic Booking, Queue & Insights

Backend for a clinic booking and queue system. One Laravel application serving
two surfaces:

- **Mobile API** (`/api/v1`) — consumed by the Flutter app the secretary uses
- **Filament panel** (`/admin`) — clinic/doctor/staff management and, later, the
  revenue and retention dashboards

Patients never sign in. See the full specification for scope and rules:
`../../../clinic-booking-system/SPEC-v1.md`.

## Stack

PHP 8.3 · Laravel 12 · Filament 4 · Sanctum · MySQL (SQLite for tests)

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The seeder creates the specialties with their default visit types and a
super-admin account from `SUPER_ADMIN_*` in `.env`.

## Layout

Mirrors the 800advance codebase so the two feel like one house style.

```
app/
  Actions/          single-purpose write operations (ProvisionClinicAction)
  DTOs/V1/          typed input crossing layer boundaries
  Enums/            backed enums — no magic strings anywhere
  Exceptions/       ApiException: renders itself as the error envelope
  Filament/         admin panel resources
  Http/
    Controllers/Api/V1/   single-action (__invoke) controllers
    Middleware/           SetApiLocale, ResolveClinic
    Requests/Api/V1/      Form Requests
  Models/
  Services/V1/            business logic
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
php artisan test           # full suite
vendor/bin/pint            # code style
php artisan migrate:fresh --seed
```

## Status

Phase 0 complete: schema, auth, roles, admin panel, API skeleton.
Next: Phase 1 — clinic settings endpoints (visit types, hours, holidays).
