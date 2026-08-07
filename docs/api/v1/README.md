# API v1 — contract for the Flutter app

Base URL: `{host}/api/v1`

Covers **Phase 0 (auth)**, **Phase 1 (clinic settings)**, **Phase 2 (booking)**
and **Phase 3 (queue & postpone)**. Later phases append to this document.

---

## 1. Conventions

### Headers

| Header | Value | Notes |
|---|---|---|
| `Accept` | `application/json` | Required |
| `Accept-Language` | `ar` \| `en` | **Always send it.** Controls `message` and every `display` field. Defaults to `ar`; anything unrecognised falls back to the account's stored language. |
| `Authorization` | `Bearer {token}` | Everything except `POST /auth/login` |

### Response envelope

Every response is one of exactly three shapes — including 404s and 500s.

**Success** — `200`, or `201` on create

```json
{ "status": "success", "message": "تم حفظ الحجز", "data": { } }
```

**Error** — `400 401 403 404 409 500`

```json
{
  "status": "error",
  "message": "الميعاد ده محجوز بالفعل",
  "error": { "code": "SLOT_UNAVAILABLE", "details": null, "fields": null }
}
```

**Validation** — `422`

```json
{
  "status": "error",
  "message": "برجاء مراجعة البيانات المدخلة",
  "error": {
    "code": "VALIDATION_FAILED",
    "details": null,
    "fields": { "duration_minutes": ["..."] }
  }
}
```

Rules:

- `status` is the string `success` or `error` — never a number.
- `data` appears only on success; `error` only on failure. The three keys inside
  `error` always exist; unused ones are `null`.
- **Branch on `error.code`, never on `message`.** Codes are stable; messages are
  localised prose meant to be shown to the user as-is.
- `details` carries structured context for a few errors (documented per endpoint).

### `value` + `display` pairs

Anything rendered as text arrives pre-formatted, so the app never re-implements
Arabic formatting:

```json
"start_time": { "value": "13:00", "display": "1:00 م" }
"date":       { "value": "2026-08-12", "display": "12 أغسطس 2026" }
"price":      { "value": "300.00", "display": "300.00 ج.م" }
"role":       { "value": "secretary", "display": "سكرتيرة" }
"day_of_week":{ "value": 0, "display": "السبت" }
```

Use `value` for logic and for sending back; show `display`.

### Clinic scope

The clinic is derived from the token. **No endpoint accepts `clinic_id`** — a
record belonging to another clinic returns `404`, never someone else's data.

### Days of the week

`0 = السبت (Saturday)` … `6 = الجمعة (Friday)`. Saturday-first, not Sunday-first.

---

## 2. Auth

### `POST /auth/login`

Rate limited to 10/minute. No token required.

```json
{ "email": "nour@clinic.test", "password": "secret", "device_name": "pixel-8" }
```

`device_name` is optional and labels the token so one device can be signed out
alone.

**200**

```json
{
  "status": "success",
  "message": "تم تسجيل الدخول",
  "data": {
    "user": {
      "id": 4,
      "name": "نور محمد",
      "email": "nour@clinic.test",
      "phone": { "value": "+201012345678", "display": "01012345678" },
      "role": { "value": "secretary", "display": "سكرتيرة" },
      "locale": "ar"
    },
    "abilities": ["bookings.manage", "queue.manage", "patients.view", "settings.manage"],
    "clinic": {
      "id": 1,
      "name": "عيادة د. سارة",
      "specialty": "نساء وتوليد",
      "timezone": "Africa/Cairo",
      "booking_window_days": 7,
      "slot_step_minutes": 10
    },
    "token": "3|xxxxxxxx",
    "token_type": "Bearer"
  }
}
```

| Code | HTTP | When |
|---|---|---|
| `INVALID_CREDENTIALS` | 401 | Wrong email **or** wrong password — deliberately indistinguishable |
| `ACCOUNT_INACTIVE` | 403 | Account deactivated |
| `FORBIDDEN_ROLE` | 403 | A super-admin account; they use the web panel, not the app |
| `VALIDATION_FAILED` | 422 | |

### `POST /auth/logout`

Revokes **only the calling token**. Other devices stay signed in. `data` is `null`.

### `GET /auth/me`

Same `data` as login, without `token`. Use it to refresh abilities after a role
change.

### `abilities`

Drives what the app shows. Present values:

| Ability | Meaning |
|---|---|
| `bookings.manage` | New booking, edit, cancel |
| `queue.manage` | Advance queue status |
| `patients.view` | Search and history |
| `settings.manage` | Settings screen |
| `prices.view` | **Owner only** — see and set prices |
| `reports.view` | Owner only — revenue and retention (web panel in v1) |

---

## 3. Bootstrap

### `GET /bootstrap`

One call on launch. Everything the settings and booking screens need.

**200 → `data`**

```json
{
  "clinic": {
    "id": 1, "name": "عيادة د. سارة", "address": "...",
    "phone": { "value": "+201012345678", "display": "01012345678" },
    "specialty": "نساء وتوليد",
    "timezone": "Africa/Cairo", "country_code": "EG",
    "booking_window_days": 7, "first_visit_only_days": 60, "slot_step_minutes": 10
  },
  "visit_types": [ /* §4 */ ],
  "schedule":    [ /* §5, always 7 entries, Saturday first */ ],
  "holidays":    [ /* §6 */ ],
  "abilities": ["..."],
  "user": { "id": 4, "name": "نور محمد", "role": "secretary" }
}
```

---

## 4. Visit types

`price` and `needs_price` are **absent entirely** for callers without
`prices.view` — not `null`. Check for the key, don't check for a value.

```json
{
  "id": 3,
  "name": "كشف",
  "duration_minutes": 20,
  "is_active": true,
  "is_new_patient_type": true,
  "sort_order": 0,
  "price": { "value": "300.00", "display": "300.00 ج.م" },
  "needs_price": false
}
```

`is_new_patient_type` marks the clinic's "this is a new concern" visit type —
usually كشف. Booking a **returning** patient under it is what raises the
visit-type mismatch warning (§9). Exactly one visit type per clinic carries the
flag; setting it on another moves it rather than duplicating it.

`needs_price` is `true` while the price is still zero — a newly provisioned
clinic starts this way, and revenue is meaningless until it's set.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/visit-types` | `?include_hidden=1` to also return hidden ones. Returns `{ "items": [...] }` |
| `POST` | `/visit-types` | `{ name, duration_minutes, price? }` → `201` |
| `PUT` | `/visit-types/{id}` | Same body. Full replacement, not a patch. |
| `DELETE` | `/visit-types/{id}` | **Hides** it — never deletes. Returns the updated record with `is_active: false`. |

A secretary may create and rename visit types; any `price` she submits is
**silently ignored**, and the existing price is preserved.

| Code | HTTP | When |
|---|---|---|
| `VISIT_TYPE_NOT_FOUND` | 404 | Unknown id, or belongs to another clinic |
| `VISIT_TYPE_DUPLICATE_NAME` | 400 | Another *active* type already has this name. A hidden one does not block reuse. |
| `VISIT_TYPE_LAST_ACTIVE` | 400 | Hiding the last active type — at least one must remain or booking becomes impossible |

Validation: `name` required, ≤100 chars · `duration_minutes` 5–480 ·
`price` ≥ 0.

---

## 5. Schedule

### `GET /schedule` → `{ "days": [...] }`

Always all seven days, Saturday first, whether open or not.

```json
{
  "day_of_week": { "value": 0, "display": "السبت" },
  "is_open": true,
  "periods": [
    {
      "id": 12,
      "start_time": { "value": "13:00", "display": "1:00 م" },
      "end_time":   { "value": "15:00", "display": "3:00 م" }
    },
    {
      "id": 13,
      "start_time": { "value": "17:00", "display": "5:00 م" },
      "end_time":   { "value": "21:00", "display": "9:00 م" }
    }
  ]
}
```

### `PUT /schedule/{day}`

`{day}` is `0`–`6`. **Replaces the whole day** — send every period you want kept.

```json
{
  "is_open": true,
  "periods": [
    { "start_time": "13:00", "end_time": "15:00" },
    { "start_time": "17:00", "end_time": "21:00" }
  ]
}
```

Period `id`s are **not stable** across a save — they are recreated each time.
Never hold onto one.

Closing a day (`is_open: false`) drops its periods, whatever you send.

| Code | HTTP | When |
|---|---|---|
| `SCHEDULE_DAY_NOT_FOUND` | 404 | `{day}` outside 0–6 |
| `SCHEDULE_PERIOD_INVALID` | 400 | An open day with no periods, or `end_time` ≤ `start_time`. `details` carries the offending period. |
| `SCHEDULE_PERIOD_OVERLAP` | 400 | Two periods overlap. `details.first` / `details.second`. |

Back-to-back periods (`09:00–12:00` then `12:00–15:00`) are allowed.
Times are `HH:mm`, 24-hour. Max 6 periods per day.

---

## 6. Holidays

One-off closures on top of the weekly schedule.

```json
{ "id": 7, "date": { "value": "2026-08-12", "display": "12 أغسطس 2026" }, "note": "سفر" }
```

| Method | Path | Notes |
|---|---|---|
| `GET` | `/holidays` | Upcoming only. `?include_past=1` for all. Returns `{ "items": [...] }` |
| `POST` | `/holidays` | `{ date, note?, force? }` → `201` |
| `DELETE` | `/holidays/{id}` | `data` is `null` |

| Code | HTTP | When |
|---|---|---|
| `HOLIDAY_ALREADY_EXISTS` | 409 | That date is already a holiday |
| `HOLIDAY_HAS_BOOKINGS` | 409 | Patients are still booked that day — see below |
| `HOLIDAY_NOT_FOUND` | 404 | Unknown id, or another clinic's |

### Closing a day that has bookings

Refused by default, with the count so you can warn properly:

```json
{
  "status": "error",
  "message": "في 3 حجز في اليوم ده. أجّلي المريضات الأول أو أكّدي إضافة الإجازة.",
  "error": {
    "code": "HOLIDAY_HAS_BOOKINGS",
    "details": { "bookings_count": 3, "date": "2026-08-12" },
    "fields": null
  }
}
```

Show the count, then either send the patients to the postpone flow, or retry
with `"force": true`. Forcing does **not** cancel anything — those bookings stay
until they're postponed.

Cancelled and completed bookings never block a holiday.

---

## 7. General settings

### `PUT /settings/general`

Both fields optional; send only what changed.

```json
{ "booking_window_days": 7, "first_visit_only_days": 60 }
```

Returns the full clinic object (same shape as `bootstrap.clinic`).

- `booking_window_days` — 1–90. How far ahead booking opens.
- `first_visit_only_days` — 1–730. A patient with one visit older than this
  counts as "never returned" in retention.

---

## 8. Booking

### `GET /booking-days` — the day strip

One entry per day of the rolling window, starting today.

```json
{
  "days": [
    {
      "date": { "value": "2026-08-08", "display": "8 أغسطس 2026" },
      "day_of_week": { "value": 0, "display": "السبت" },
      "day_number": 8,
      "is_open": true,
      "is_holiday": false,
      "is_today": true,
      "bookings_count": 4,
      "pending_count": 2
    }
  ]
}
```

- `is_open` is already false on a holiday — check `is_holiday` only to show
  "إجازة" rather than a date.
- `pending_count` is the "X لسه ماخلصوش" number: booked + arrived.
- `bookings_count` excludes cancelled bookings.

### `GET /slots?date=&visit_type_id=`

**Both parameters are required, and the slot list changes with the visit
type** — a 30-minute procedure offers fewer start times than a 10-minute
follow-up. Re-fetch whenever the secretary changes the type.

```json
{
  "date": { "value": "2026-08-08", "display": "8 أغسطس 2026" },
  "is_open": true,
  "closed_reason": null,
  "visit_type": { "id": 3, "name": "كشف", "duration_minutes": 20 },
  "available_count": 9,
  "slots": [
    {
      "start_time": { "value": "09:00", "display": "9:00 ص" },
      "end_time":   { "value": "09:20", "display": "9:20 ص" },
      "is_available": false
    }
  ]
}
```

**Unavailable slots are returned, not omitted** — render them greyed and struck
through, as in the mockup.

When the day yields nothing, `is_open` is `false`, `slots` is `[]`, and
`closed_reason` explains it:

| `closed_reason.value` | Meaning |
|---|---|
| `weekly_closed` | Not a working day |
| `holiday` | One-off closure |
| `outside_window` | Past, or beyond `booking_window_days` |

Rules the server applies, so the app doesn't have to:

- Start times step by the clinic's `slot_step_minutes` (default 10).
- A visit must **finish** inside the period — 11:50 is not offered for a
  20-minute visit when the clinic closes at 12:00.
- A slot is unavailable if it overlaps any existing booking of **any** visit
  type. Cancelled bookings free their slot; completed ones do not.
- Touching is fine: a visit starting exactly when another ends is available.

### `POST /patients/lookup`

Call as soon as the phone is complete. `name` and `visit_type_id` are optional,
so it can be called again as the form fills in.

```json
{ "phone": "01012225521", "name": "سارة أحمد", "visit_type_id": 3 }
```

**200**

```json
{
  "found": true,
  "phone": { "value": "+201012225521", "display": "01012225521" },
  "patient": { "id": 12, "code": "SAAH5521", "name": "سارة أحمد", "visits_count": 3 },
  "is_returning": true,
  "name_conflict": false,
  "visit_type_mismatch": true,
  "last_visit": {
    "date": { "value": "2026-07-02", "display": "2 يوليو 2026" },
    "visit_type": { "id": 4, "name": "إعادة" }
  }
}
```

Three flags drive the UI:

| Flag | Meaning | What to show |
|---|---|---|
| `found` | The phone is already on file | Prefill the name |
| `name_conflict` | The typed name differs from the stored one | Ask: keep the stored name, or correct it — then send `update_patient_name: true` |
| `visit_type_mismatch` | A returning patient is being booked under the "new concern" type | The inline warning with "تصحيح" / "تأكيد" |

Patients are matched on the **normalised phone only**, never on name — `01012225521`,
`+201012225521` and `00201012225521` are the same person. A patient belongs to
one clinic; another clinic's patient is never returned.

`INVALID_PHONE_NUMBER` (422) when the number cannot be parsed.

### `POST /bookings`

```json
{
  "patient_name": "سارة أحمد",
  "phone": "01012225521",
  "visit_type_id": 3,
  "date": "2026-08-08",
  "start_time": "09:00",
  "notes": null,
  "force": false,
  "update_patient_name": false
}
```

- The patient is created if the phone is new, and reused if it is known — the
  ID code is generated once and never changes.
- `update_patient_name: true` replaces the stored name. Without it a differing
  name is ignored, so a typo cannot quietly rewrite the record.
- `force: true` books anyway — past a taken slot, outside working hours, or on
  a closed day — and sets `is_overbooked` on the result. Use it only after the
  secretary confirms.

**201 → `data`**

```json
{
  "id": 88,
  "status": { "value": "booked", "display": "محجوزة" },
  "cancel_reason": null,
  "patient": {
    "id": 12, "code": "SAAH5521", "name": "سارة أحمد",
    "phone": { "value": "+201012225521", "display": "01012225521" }
  },
  "visit_type": { "id": 3, "name": "كشف", "duration_minutes": 20 },
  "date": { "value": "2026-08-08", "display": "8 أغسطس 2026" },
  "start_time": { "value": "09:00", "display": "9:00 ص" },
  "end_time": { "value": "09:20", "display": "9:20 ص" },
  "is_overbooked": false,
  "notes": null,
  "price": { "value": "300.00", "display": "300.00 ج.م" }
}
```

`price` appears only for callers with `prices.view`. `visit_type.duration_minutes`
is the value **snapshotted at booking time**, not the visit type's current one.

| Code | HTTP | When |
|---|---|---|
| `SLOT_UNAVAILABLE` | 409 | Overlaps an existing booking |
| `SLOT_OUTSIDE_WORKING_HOURS` | 409 | Not an offered start time, or the visit would run past closing |
| `CLINIC_CLOSED_THAT_DAY` | 409 | Weekly closed, or a holiday. `details.reason` distinguishes them |
| `SLOT_OUTSIDE_WINDOW` | 409 | Past, or beyond the booking window |
| `VISIT_TYPE_NOT_FOUND` | 404 | Unknown, or another clinic's |
| `VISIT_TYPE_INACTIVE` | 400 | Hidden visit type |
| `INVALID_PHONE_NUMBER` | 422 | |

All of these are cleared by `force: true` except the visit-type errors.

### `GET /bookings/{id}` · `PUT /bookings/{id}`

`PUT` takes the **same body as create** — it is a full replacement, and it
re-runs the whole availability check. The booking never conflicts with itself.

Editable while `booked` or `arrived` only. Once the patient is with the doctor
it must be completed, not edited:

| Code | HTTP | When |
|---|---|---|
| `BOOKING_NOT_FOUND` | 404 | Unknown, or another clinic's |
| `BOOKING_NOT_EDITABLE` | 400 | Status is `with_doctor`, `done` or `cancelled`. `details.status` says which |

Changing the visit type re-snapshots price and duration, and moves `end_time`.
Changing the phone moves the booking to a different patient.

---

## 9. Today's queue

### `GET /queue?date=&include_cancelled=`

`date` defaults to today in the clinic's timezone. Cancelled bookings are
excluded unless `include_cancelled=1` (the "الملغية" toggle).

```json
{
  "date": { "value": "2026-08-08", "display": "8 أغسطس 2026" },
  "is_open": true,
  "is_holiday": false,
  "counts": { "pending": 2, "done": 1, "cancelled": 0, "total": 3 },
  "awaiting_rebooking_count": 0,
  "items": [
    {
      "id": 88,
      "status": { "value": "with_doctor", "display": "مع الدكتورة" },
      "queue_position": 1,
      "available_actions": ["complete"],
      "arrived_at": "09:05",
      "contacted_at": null,
      "patient": { "id": 12, "code": "SAAH5521", "name": "سارة أحمد",
                   "phone": { "value": "+201012225521", "display": "01012225521" } },
      "visit_type": { "id": 3, "name": "كشف", "duration_minutes": 20 },
      "date": { "value": "2026-08-08", "display": "8 أغسطس 2026" },
      "start_time": { "value": "09:00", "display": "9:00 ص" },
      "end_time": { "value": "09:20", "display": "9:20 ص" },
      "is_overbooked": false,
      "notes": null
    }
  ]
}
```

### Ordering — by arrival, not appointment time

The list is already sorted. Render it in the order given:

1. `with_doctor`
2. `arrived`, earliest check-in first
3. `booked`, by appointment time
4. `done`
5. `cancelled` (only when requested)

**The list re-sorts during the day** — a patient who checks in moves above
people booked earlier, and someone booked at 09:00 who arrives at 10:30 goes
behind everyone already waiting. This is intended; tell the secretary so it
isn't reported as a bug.

### `queue_position`

Only patients physically in the clinic hold a number — `arrived` and
`with_doctor`. For everyone else it is `null`:

| Status | Badge to show |
|---|---|
| `with_doctor`, `arrived` | `queue_position` |
| `booked` | The appointment time — no number |
| `done` | ✓ |
| `cancelled` | ✗ |

Positions renumber as the day moves; re-read them from every response rather
than caching.

### `available_actions`

The server tells you which buttons the card may show, so the transition rules
live in one place:

| Action | Endpoint | Label |
|---|---|---|
| `arrive` | `POST /bookings/{id}/arrive` | تسجيل الوصول |
| `call_in` | `POST /bookings/{id}/call-in` | استدعاء للداخل |
| `complete` | `POST /bookings/{id}/complete` | إنهاء الزيارة |
| `call` | `tel:` link | (phone icon) |
| `edit` | `PUT /bookings/{id}` | تعديل |
| `no_show` | `POST /bookings/{id}/cancel` `{"reason":"no_show"}` | لم تحضر |
| `cancel` | `POST /bookings/{id}/cancel` `{"reason":"patient_cancelled"}` | إلغاء |

**Confirm every one of these with a dialog before calling it** — that is a rule
across the whole app, not a per-screen choice.

### Transitions

Each returns the updated card (same shape as a queue item).

```
booked --arrive--> arrived --call_in--> with_doctor --complete--> done
```

| Code | HTTP | When |
|---|---|---|
| `INVALID_STATUS_TRANSITION` | 409 | Skipping a step, or advancing a finished booking. `details.from`, `details.to`, `details.expected` |
| `BOOKING_NOT_CANCELLABLE` | 400 | Cancelling a patient who is already with the doctor — complete her instead |
| `BOOKING_NOT_FOUND` | 404 | |

`POST /bookings/{id}/cancel` takes `reason`: **`no_show`** or
**`patient_cancelled`** only. `emergency` and `incomplete` are system-set and
rejected with `VALIDATION_FAILED`.

Cancelling **frees the slot immediately**, whatever the reason.

---

## 10. Postpone & the call list

Replaces the PRD's WhatsApp emergency broadcast, which is deferred to v2.
**Nothing is messaged** — the bookings are cancelled and you get a worklist.

### `GET /postpone/candidates?date=`

Today's patients still in play (`booked` + `arrived`) — the multi-select list
behind "مريضات محددة". Same row shape as the call list below.

### `POST /postpone`

```json
{ "date": "2026-08-08", "booking_ids": [88, 91] }
```

Omit `booking_ids` entirely for "كل المريضات". `date` defaults to today.

Cancels them with reason `emergency`, frees their slots, and returns the call
list plus `postponed_count`.

`NOTHING_TO_POSTPONE` (400) when the selection is empty — including when the
only patient left is already with the doctor, who is never postponed.

### `GET /rebooking-list`

Everyone postponed and not yet rebooked. Reachable any time from the
home-screen banner — `awaiting_rebooking_count` on the queue drives it.

```json
{
  "items": [
    {
      "booking_id": 88,
      "patient": { "id": 12, "code": "SAAH5521", "name": "سارة أحمد",
                   "phone": { "value": "+201012225521", "display": "01012225521" } },
      "visit_type": { "id": 3, "name": "كشف" },
      "original_date": { "value": "2026-08-08", "display": "8 أغسطس 2026" },
      "original_start_time": { "value": "09:00", "display": "9:00 ص" },
      "contacted": false,
      "contacted_at": null
    }
  ]
}
```

Phones are **unmasked** here — every row has a call action.

### Working the list

1. **`POST /bookings/{id}/contacted`** — ticks "تم الاتصال" so the secretary
   keeps her place. It does not rebook anyone.
2. **`POST /bookings`** with **`rebooking_for_booking_id`** set to the old
   booking's id. That links the replacement and drops the patient off the list.
   Without it she stays on the list forever.

`BOOKING_NOT_FOUND` (404) if that booking isn't actually awaiting rebooking.

---

## 11. End of day

A scheduled job closes out days that have finished, on **each clinic's own
clock**:

| Left in status | Becomes |
|---|---|
| `booked` | `cancelled` / `no_show` |
| `arrived`, `with_doctor` | `cancelled` / `incomplete` |

Neither counts toward revenue. Nothing today or in the future is ever touched.
The app needs no handling for this beyond re-reading the queue.

---

## 12. Error code index

| Code | HTTP |
|---|---|
| `VALIDATION_FAILED` | 422 |
| `INVALID_CREDENTIALS` | 401 |
| `ACCESS_TOKEN_MISSING` | 401 |
| `ACCOUNT_INACTIVE` | 403 |
| `FORBIDDEN_ROLE` | 403 |
| `CLINIC_NOT_ASSIGNED` | 403 |
| `CLINIC_INACTIVE` | 403 |
| `RESOURCE_NOT_FOUND` | 404 |
| `VISIT_TYPE_NOT_FOUND` | 404 |
| `VISIT_TYPE_DUPLICATE_NAME` | 400 |
| `VISIT_TYPE_LAST_ACTIVE` | 400 |
| `SCHEDULE_DAY_NOT_FOUND` | 404 |
| `SCHEDULE_PERIOD_INVALID` | 400 |
| `SCHEDULE_PERIOD_OVERLAP` | 400 |
| `HOLIDAY_ALREADY_EXISTS` | 409 |
| `HOLIDAY_HAS_BOOKINGS` | 409 |
| `HOLIDAY_NOT_FOUND` | 404 |
| `SLOT_UNAVAILABLE` | 409 |
| `SLOT_OUTSIDE_WORKING_HOURS` | 409 |
| `SLOT_OUTSIDE_WINDOW` | 409 |
| `CLINIC_CLOSED_THAT_DAY` | 409 |
| `VISIT_TYPE_INACTIVE` | 400 |
| `BOOKING_NOT_FOUND` | 404 |
| `BOOKING_NOT_EDITABLE` | 400 |
| `INVALID_PHONE_NUMBER` | 422 |
| `INVALID_STATUS_TRANSITION` | 409 |
| `BOOKING_NOT_CANCELLABLE` | 400 |
| `NOTHING_TO_POSTPONE` | 400 |
| `INTERNAL_SERVER_ERROR` | 500 |

Codes are only ever added, never renamed. Treat an unrecognised code as a
generic failure and show `message`.
