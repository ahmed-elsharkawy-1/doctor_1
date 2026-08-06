# API v1 — contract for the Flutter app

Base URL: `{host}/api/v1`

Covers **Phase 0 (auth)** and **Phase 1 (clinic settings)**. Later phases append
to this document.

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
  "sort_order": 0,
  "price": { "value": "300.00", "display": "300.00 ج.م" },
  "needs_price": false
}
```

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

## 8. Error code index

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
| `INTERNAL_SERVER_ERROR` | 500 |

Codes are only ever added, never renamed. Treat an unrecognised code as a
generic failure and show `message`.
