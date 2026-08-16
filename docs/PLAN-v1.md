# doctor_1 — v1 implementation plan

Self-contained brief. Everything needed to do this work without prior context.

---

## 1. The project

**doctor_1** — a clinic booking, queue and insights system for Egyptian clinics.
Arabic RTL, staff-only. Patients never log in.

- **Repo:** `/home/ahmed/Desktop/work/general/workspace/doctor_1`
- **Spec:** `/home/ahmed/Desktop/work/clinic-booking-system/SPEC-v1.md` (read §0 first — it describes the current v1 design delta)
- **Stack:** PHP 8.3 · Laravel 12 · Filament 4 · Sanctum · MySQL (`prac_doctor_app`), SQLite in tests
- **Surfaces:** a Flutter mobile app via `/api/v1`, and a Filament panel at `/admin` for the platform operator only

**Current state:** v1 is complete and green — ~290 tests. This plan records the
updates made against the Figma design that is now the source of truth.

**Implementation mode:** implement this directly in the current project line.
There is no mobile integration yet, so no separate compatibility branch is
required. Treat the work as one coordinated API revision and keep the test suite
green at the end of each step.

---

## 2. House rules — follow these exactly

Match the existing code; do not introduce new patterns.

**Layering** (mirrors the 800advance codebase)

```
app/Http/Controllers/Api/V1/<Domain>/   single-action __invoke, extends V1Controller
app/Http/Requests/Api/V1/<Domain>/      Form Requests
app/Services/V1/<Domain>/               business logic
app/Services/Results/V1/<Domain>/       payload shaping, extends ServiceResult
app/DTOs/V1/<Domain>/                   typed input across boundaries
app/Enums/                              backed enums — never magic strings
app/Actions/                            single-purpose write operations
app/Support/                            ApiResponse, Wire, PhoneNumber
```

**Response envelope** — build only through `App\Support\ApiResponse`:

```json
{ "status": "success", "message": "...", "data": {} }
{ "status": "error",   "message": "...", "error": { "code": "...", "details": null, "fields": null } }
```

`error.code` is a stable UPPER_SNAKE value from `ApiErrorCode` / `AuthErrorCode`.
Clients branch on the code, never the message.

**`value` + `display` pairs** — anything rendered as text ships pre-formatted
via `App\Support\Wire`, driven by `Accept-Language`.

**Non-negotiables**

- Nothing configurable is hardcoded: system defaults in `config/clinic.php`, per-clinic values as columns on `clinics`, fixed sets as backed enums
- Every user-facing string through `__()`, with matching keys in `lang/ar` **and** `lang/en`
- `clinic_id` never comes from the client — the `clinic` middleware resolves it from the token. Another clinic's record returns **404**, never its data
- Records are deactivated, never deleted. `price` and `duration_minutes` are snapshotted onto each booking so history cannot be rewritten
- Booking times are stored as the clinic's **local wall clock**. Re-attach the clinic timezone explicitly when comparing; compare calendar days as date strings, never as instants
- `vendor/bin/pint` before finishing
- `php artisan test` (SQLite) **and** `php artisan test -c phpunit.mysql.xml` (MySQL) must both pass. The two drivers have disagreed before
- **Never point the test suite at a real database.** `phpunit.xml` pins SQLite with `force="true"` — leave that alone

---

## 3. What changed, and why

The design is now the source of truth. Six earlier decisions are reversed:

| Was | Now |
|---|---|
| WhatsApp deferred to v2 | **In scope**, via approved templates |
| Queue ordered by arrival | **Ordered by appointment time** — no card shows a queue position, and the screen now spans any day |
| Login by phone | **Login by clinic email** |
| Owner + secretary roles | **One clinic account**, shared by doctor and assistant |
| Patient code `SAAH5521` | **5-digit numeric derived from `id`** |
| No-show as a cancel reason | **A status of its own** |

Accepted consequence: prices are visible to whoever signs in. Per-person audit
is not reliable with one shared clinic account; activity logging is explicitly
deferred from this implementation pass.

---

## 4. The work

Do the steps in order. Steps 1–3 intentionally break existing tests — update
them as you go; never leave the suite red.

---

### Step 1 — Six statuses, and stop offering past times

**1a. `no_show` becomes a status**

- Add `NO_SHOW = 'no_show'` to `App\Enums\BookingStatus`
- Remove `NO_SHOW` from `App\Enums\CancelReason`; `selectable()` becomes `[PATIENT_CANCELLED]`
- Migration to convert existing rows:
  `status='cancelled' AND cancel_reason='no_show'` → `status='no_show'`, `cancel_reason=NULL`
- Transitions: reachable from `booked` and `arrived`. `done` and `no_show` are terminal — the cancel action is hidden on both (per the design annotation)
- `BookingStatus::occupyingSlot()` excludes **both** `cancelled` and `no_show` — a no-show frees its slot
- End-of-day command: leftover `booked` → `no_show` (the status, not a reason); leftover `arrived`/`with_doctor` → `cancelled` + `incomplete`
- Arabic labels: `arrived` → **داخل العيادة**, `with_doctor` → **قيد الكشف**, `no_show` → **لم يحضر**

**1b. Past start times are not offered** *(bug fix)*

`SlotAvailabilityService` currently checks the date only, never the clock. At
12:56 it still offers 09:00 today as free.

- When the requested date is today, **omit** candidates whose start time has passed in the clinic's timezone
- **Omit, do not mark unavailable.** Taken slots stay visible and struck through so the secretary sees they are booked; past slots are just noise
- This is a **picker rule only**. `force: true` must still book a past time, so a walk-in already seen can be recorded

**Tests:** a no-show frees its slot; past times absent from today's list but present for tomorrow; `force` still books one; the end-of-day command sets `no_show`.

---

### Step 2 — Clinic login and one role

**2a. Login by clinic email**

- `LoginRequest` takes `email` instead of `phone`
- No minimum length on the password — a short wrong guess must return `INVALID_CREDENTIALS`, not `VALIDATION_FAILED`

**2b. Collapse the roles**

- `App\Enums\UserRole` becomes `SUPER_ADMIN` and `CLINIC`
- `CLINIC` holds every clinic ability, prices and reports included
- `usesPanel()` stays super-admin only
- Update the Filament `UserResource` to manage clinic accounts
- Delete the `prices.view` / `reports.view` gating **inside** the app — one account sees everything. Keep the ability constants; they still separate panel from app

**2c. Activity log — deferred**

Do **not** build `activity_logs` in this pass. The one-account model is accepted
without audit until the product explicitly needs device-level logging. Keep the
plan honest in docs: one shared password means the system cannot identify which
person performed an action.

---

### Step 3 — Patient record

**3a. Numeric code**

Replace the transliterated `SAAH5521` scheme with a number derived from the row id:

```php
// config/clinic.php
'patient_code' => [
    'start_at'   => 60000,
    'step'       => 1,
    'min_length' => 5,
],
```

```
code = str_pad(start_at + (id * step), min_length, '0', STR_PAD_LEFT)
```

- Assigned **after insert** (the id is needed) and **immutable** thereafter
- Keep the existing `patients.code` column and its unique index
- **Delete** `App\Support\ArabicTransliterator`, `App\Actions\Patient\GeneratePatientCodeAction` and their tests
- Backfill migration for existing rows

**3b. New columns**

- `age` — nullable, from **السن** on the new-patient form
- `whatsapp_opt_in_at` — nullable timestamp. **No message is ever sent to a patient without it**

Add an opt-in flag to patient creation, defaulting to on, so consent is recorded rather than assumed.

**3c. Booking an existing patient**

- `POST /bookings` accepts **`patient_id`** as an alternative to `patient_name` + `phone` — the design's step 1 picks a patient from search results
- Validation is mutually exclusive:
  - either `patient_id` is present
  - or both `patient_name` and `phone` are present
- If `patient_id` is present, the patient must belong to the authenticated clinic
- A `patient_id` from another clinic returns **404**, not validation details
- If `patient_id` is present, ignore `patient_name`, `phone`, and `update_patient_name`; editing patient details remains a separate patient concern
- Keep `visit_type_id`, `date`, `start_time`, `force`, `notes`, and `rebooking_for_booking_id` behavior unchanged
- Patient search results gain **`last_visit`** (date of the most recent visit) — the design shows اخر زيارة on each result
- Search already matches name, code prefix and phone tail. Confirm the numeric code still matches as a prefix

---

### Step 4 — The calendar endpoint

This is the largest piece. It **replaces `/queue` and `/booking-days`** — the
design has no separate queue screen; **الحجوزات is the queue**.

```
GET /api/v1/bookings/calendar?from=2026-10-20&to=2026-10-26&status=booked
```

```json
{
  "range": { "from": "2026-10-20", "to": "2026-10-26" },

  "days": [
    { "date": {"value":"2026-10-25","display":"25 أكتوبر 2026"},
      "day_of_week": {"value":5,"display":"الخميس"},
      "day_number": 25,
      "is_open": true, "is_holiday": false, "is_today": true,
      "counts": { "total": 6, "booked": 2, "arrived": 1, "with_doctor": 1,
                  "done": 2, "cancelled": 0, "no_show": 0 } }
  ],

  "bookings": {
    "2026-10-25": [ { card }, { card } ],
    "2026-10-24": [ { card } ]
  }
}
```

**Rules**

- `days[]` is **dense** — every day in the range, so the strip renders and closed days are marked
- `bookings` is **sparse and date-keyed** — only days that have bookings, matching the 800advance `my-bookings/calendar` convention. Keys double as the calendar's dots
- Keys ascending; **within a day, ordered by appointment time ascending**
- `status=` filters **the cards only, never the counts** — the chips keep showing every number while one is selected
- Past days are included and readable
- Cards carry no `queue_position` — the concept is removed

**Card changes**

- **Remove** `queue_position`, and keep appointment-time ordering on calendar cards.
- **Keep** `arrived_at` — no longer used for ordering, but it is the only way to measure waiting time later
- **Add** `next_status: { value, display } | null` so the app renders **"تغيير إلى ( داخل العيادة )"** without hard-coding the chain
- `available_actions` keeps `edit`, `cancel`, `no_show`, `call`, `whatsapp` — the transition verbs move to `next_status`. `cancel` is allowed through `with_doctor`; `no_show` is only before/at arrival.

**Collapse the transition endpoints**

Replace `arrive` / `call-in` / `complete` with one:

```
POST /api/v1/bookings/{id}/status     { "to": "arrived" }
```

`to` is **required**, not implied. On a double tap the second call still sends
`to: "arrived"` while the booking is already `arrived`, so it fails with
`INVALID_STATUS_TRANSITION` instead of silently advancing to قيد الكشف.

`POST /bookings/{id}/cancel { reason }` stays separate — it carries a reason and is not "the next step".

**Net: 31 endpoints → 28.**

---

### Step 5 — Home screen

```
GET /api/v1/home
```

Returns:

- `today` — total plus a count per status (the six chips on الرئيسية)
- `upcoming` — the next appointments, for **الكشوفات القادمة**

Reuse the calendar's counting logic; do not duplicate it.

---

### Step 6 — WhatsApp messaging

Template texts are ready in `docs/whatsapp/templates.md`. Meta approval is
handled separately and **blocks nothing** — build behind a driver.

**Templates are mandatory.** WhatsApp does not permit free-form messages to
someone who has not messaged the business in the last 24 hours, and patients
never message the clinic. The design's message box is therefore a **preview of
the chosen template**, not an editor.

**Schema**

`message_templates` — global, since one WhatsApp Business Account serves every clinic:

| Column | |
|---|---|
| `key` | `day_cancelled`, `appointment_earlier`, `appointment_delayed` |
| `category` | `utility` |
| `body_ar` | with `{{1}}` patient name, `{{2}}` clinic name |
| `provider_template_name` | the name registered with Meta |
| `is_active` | |

`outbound_messages`:

| Column | |
|---|---|
| `clinic_id`, `patient_id`, `booking_id` | booking nullable |
| `template_key`, `rendered_body` | |
| `status` | `queued` / `sent` / `delivered` / `failed` |
| `provider_message_id`, `error` | |
| `sent_at`, `delivered_at` | |

**Sending**

- Interface `MessageSender` with two drivers, selected in `config/clinic.php`
- **`log`** — ships now: renders, records, marks sent. Nothing leaves the building
- **`cloud_api`** — swapped in by configuration when Meta approves. **No application code changes**
- One queued job per message, so rate limits and retries are handled
- Do not call the external WhatsApp Cloud API in this pass. The `cloud_api`
  implementation can be a config-ready seam or explicit placeholder, but the
  working driver is `log`.

**Endpoints**

```
GET  /api/v1/message-templates
POST /api/v1/broadcasts            { template_key, date?, booking_ids?[] }
POST /api/v1/bookings/{id}/message { template_key }
```

**Behaviour**

| Template | Effect |
|---|---|
| `day_cancelled` | Sends **and cancels** the selected bookings (reason `emergency`), freeing their slots — otherwise the day still looks full and the schedule lies |
| `appointment_earlier` | **Notify only** |
| `appointment_delayed` | **Notify only** |

Omitting `booking_ids` targets everyone still pending that day (كل المريضات).

**Opt-in gate:** skip patients with no `whatsapp_opt_in_at` and report them in
the response, so the secretary knows who was not reached.

Seed the three templates.

---

### Step 7 — Documentation

- Update `docs/api/v1/openapi.yaml` — it is the source of truth and a test fails if a route is missing from it
- `php artisan api:postman` to regenerate the collection
- Update `docs/api/v1/README.md`
- Update `/home/ahmed/Desktop/work/clinic-booking-system/SPEC-v1.md` wording so it says: patients never log in; opted-in patients may receive clinic-initiated WhatsApp template messages
- Confirm `/docs/api` still renders

---

## 5. Open items — confirm before or during

1. **نوع الحجز on the booking card** — resolved as the visit type name; the card renders `visit_type.name`. No work.
2. **Rebooking list** (`/rebooking-list`, `contacted_at`, `rebooked_booking_id`) — built and tested, but the new design has no screen for it. `day_cancelled` still cancels bookings, so the *need* exists. **Keep it** unless the design team confirms it is gone.
3. **`first_visit_only_days`** — the retention maturity window has no screen. Keep as a setting.
4. **Activity log** — deferred. Revisit only if device-level audit becomes a product requirement.

---

## 6. Definition of done

- [x] `php artisan test` green
- [x] `php artisan test -c phpunit.mysql.xml` green
- [x] `vendor/bin/pint` clean
- [x] `openapi.yaml` covers every route; Postman collection regenerated
- [x] Every new string has both `ar` and `en` keys
- [x] No new hardcoded values — config, clinic column, or enum
- [x] `DemoClinicSeeder` still produces a working clinic, including the six statuses and some messages
- [x] Activity logging remains out of scope and is not referenced as required behavior in docs/OpenAPI

---

## 7. Complete implementation checklist

Use this checklist to execute the work without losing cross-cutting updates.

### 7.1 Status model and slot behavior

- [x] Add `BookingStatus::NO_SHOW`
- [x] Remove `CancelReason::NO_SHOW` and update selectable reasons
- [x] Represent no-show directly as `status = no_show` in the main booking schema/model
- [x] Update labels/translations for all six statuses
- [x] Update lifecycle helpers: terminal, cancellable, occupying slot, pending
- [x] Update end-of-day command behavior
- [x] Omit past start times from today's slot picker
- [x] Keep `force: true` able to book past times
- [x] Update factories, seeders, enum tests, slot tests, close-day tests

### 7.2 Auth and clinic account

- [x] Change login input from `phone` to `email`
- [x] Query users by email in `AuthService`
- [x] Preserve password rule with no minimum length
- [x] Collapse roles to `SUPER_ADMIN` and `CLINIC`
- [x] Give `CLINIC` all mobile abilities, including prices and reports
- [x] Keep `usesPanel()` super-admin only
- [x] Update Filament user resource/factories/seeders/tests
- [x] Remove in-app filtering based on `prices.view` / `reports.view`

### 7.3 Patient record and patient selection

- [x] Replace transliteration code generation with numeric id-derived code
- [x] Backfill existing patient codes
- [x] Delete Arabic transliterator/action and their tests
- [x] Add `age` and `whatsapp_opt_in_at`
- [x] Add opt-in input to patient creation with consent recorded
- [x] Accept `patient_id` as booking input alternative
- [x] Enforce the exact `patient_id` validation rules from Step 3c
- [x] Add `last_visit` to patient search results
- [x] Confirm name, numeric-code prefix, and phone-tail search

### 7.4 Calendar and booking card contract

- [x] Add `GET /api/v1/bookings/calendar`
- [x] Replace `/queue` and `/booking-days`
- [x] Return dense `days[]` and sparse date-keyed `bookings`
- [x] Order cards by appointment time ascending
- [x] Apply `status` filter to cards only, not counts
- [x] Remove `queue_position`
- [x] Retire arrival-based ordering from calendar cards; keep legacy internals only for postponed/rebooking support
- [x] Add `next_status`
- [x] Keep `arrived_at`
- [x] Return available actions: `edit`, `cancel`, `no_show`, `call`, `whatsapp`
- [x] Add `POST /api/v1/bookings/{id}/status`
- [x] Remove `arrive`, `call-in`, and `complete` endpoints
- [x] Keep cancel endpoint separate

### 7.5 Home endpoint

- [x] Add `GET /api/v1/home`
- [x] Return today's total and six status counts
- [x] Return upcoming appointments for الكشوفات القادمة
- [x] Reuse calendar counting logic

### 7.6 WhatsApp log-driver messaging

- [x] Add message template and outbound message schema
- [x] Seed three approved templates
- [x] Add `MessageSender` interface
- [x] Implement `log` driver as the only working driver in this pass
- [x] Add config for driver selection
- [x] Add queued job per message
- [x] Add message template endpoint
- [x] Add broadcast endpoint
- [x] Add per-booking message endpoint
- [x] Implement opt-in skip/report behavior
- [x] `day_cancelled` sends and cancels target bookings with `emergency`
- [x] `appointment_earlier` and `appointment_delayed` notify only

### 7.7 Docs and verification

- [x] Update OpenAPI
- [x] Regenerate Postman collection
- [x] Update API README
- [x] Update external spec wording about patient WhatsApp messages
- [x] Confirm `/docs/api` renders
- [x] Run `vendor/bin/pint`
- [x] Run SQLite test suite
- [x] Run MySQL test suite
