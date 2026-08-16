# doctor_1 — v1.1 implementation plan

Self-contained brief. Everything needed to do this work without prior context.

---

## 1. The project

**doctor_1** — a clinic booking, queue and insights system for Egyptian clinics.
Arabic RTL, staff-only. Patients never log in.

- **Repo:** `/home/ahmed/Desktop/work/general/workspace/doctor_1`
- **Spec:** `/home/ahmed/Desktop/work/clinic-booking-system/SPEC-v1.md` (read §0 first — it is the v1.1 delta)
- **Stack:** PHP 8.3 · Laravel 12 · Filament 4 · Sanctum · MySQL (`prac_doctor_app`), SQLite in tests
- **Surfaces:** a Flutter mobile app via `/api/v1`, and a Filament panel at `/admin` for the platform operator only

**Current state:** v1.0 complete and green — ~290 tests. This plan revises it
against an updated Figma design that is now the source of truth.

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

Accepted consequences: prices are visible to whoever signs in; per-person audit
is replaced by device-level logging.

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

### Step 2 — Clinic login, one role, activity log

**2a. Login by clinic email**

- `LoginRequest` takes `email` instead of `phone`
- No minimum length on the password — a short wrong guess must return `INVALID_CREDENTIALS`, not `VALIDATION_FAILED`

**2b. Collapse the roles**

- `App\Enums\UserRole` becomes `SUPER_ADMIN` and `CLINIC`
- `CLINIC` holds every clinic ability, prices and reports included
- `usesPanel()` stays super-admin only
- Update the Filament `UserResource` to manage clinic accounts
- Delete the `prices.view` / `reports.view` gating **inside** the app — one account sees everything. Keep the ability constants; they still separate panel from app

**2c. Activity log**

New table `activity_logs`:

| Column | |
|---|---|
| `clinic_id`, `user_id` | |
| `action` | e.g. `booking.created`, `booking.status_changed`, `broadcast.sent` |
| `subject_type`, `subject_id` | nullable, polymorphic |
| `device_name` | from the Sanctum token name, captured at login |
| `ip_address` | |
| `properties` | nullable JSON — e.g. `{"from":"booked","to":"arrived"}` |
| `created_at` | |

Record on: login, booking created/updated, status changed, cancelled, broadcast sent.

**Be honest in the docs:** with a shared password this identifies the *device*,
not the person.

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

- **Remove** `queue_position`, and the arrival-based ordering (`queueWeight`, `holdsQueuePosition`, `queueSortKey`, `QueueService::positions`)
- **Keep** `arrived_at` — no longer used for ordering, but it is the only way to measure waiting time later
- **Add** `next_status: { value, display } | null` so the app renders **"تغيير إلى ( داخل العيادة )"** without hard-coding the chain
- `available_actions` keeps `edit`, `cancel`, `no_show`, `call`, `whatsapp` — the transition verbs move to `next_status`

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
- Confirm `/docs/api` still renders

---

## 5. Open items — confirm before or during

1. **نوع الحجز on the booking card** — resolved as the visit type name; the card renders `visit_type.name`. No work.
2. **Rebooking list** (`/rebooking-list`, `contacted_at`, `rebooked_booking_id`) — built and tested, but the new design has no screen for it. `day_cancelled` still cancels bookings, so the *need* exists. **Keep it** unless the design team confirms it is gone.
3. **`first_visit_only_days`** — the retention maturity window has no screen. Keep as a setting.

---

## 6. Definition of done

- [ ] `php artisan test` green
- [ ] `php artisan test -c phpunit.mysql.xml` green
- [ ] `vendor/bin/pint` clean
- [ ] `openapi.yaml` covers every route; Postman collection regenerated
- [ ] Every new string has both `ar` and `en` keys
- [ ] No new hardcoded values — config, clinic column, or enum
- [ ] `DemoClinicSeeder` still produces a working clinic, including the six statuses and some messages
