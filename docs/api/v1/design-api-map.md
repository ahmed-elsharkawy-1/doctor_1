# API v1 to Figma design map

This document links the current v1 API contract to the new mobile design.

- Figma design root: https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=157-1769
- Live API docs: https://doctor1.srv1362420.hstgr.cloud/docs/api
- Live handoff: https://doctor1.srv1362420.hstgr.cloud/docs/api/handoff
- Live OpenAPI JSON: https://doctor1.srv1362420.hstgr.cloud/docs/api/openapi.json
- Source of truth: `docs/api/v1/openapi.yaml`

## Executive summary

The current v1 API is aligned with the design version we implemented. It has 33
operations across 9 API groups:

| API group | Operations | Primary design areas |
|---|---:|---|
| Auth | 2 | Login screen and logout action |
| Bootstrap | 2 | App launch, home/dashboard summary |
| Settings | 10 | Visit types, weekly hours, holidays, general settings |
| Booking | 6 | Calendar, day bookings, slot picker, new/edit booking |
| Status | 2 | Booking card actions and status pills |
| Postpone | 4 | Emergency postpone flow and rebooking list |
| Messaging | 3 | WhatsApp template list, broadcast, one-booking message |
| Patients | 2 | Patient search and patient file/history |
| Reports | 2 | Revenue and retention screens |

The main design-to-API rule is: the app should display `display` values and send
back `value` values. The API already formats Arabic dates, times, prices, roles,
statuses, and days of week.

## Design anchors

The Figma file uses many repeated iPhone frames. These are the stable anchors I
confirmed from the design metadata:

| Design area | Figma anchor |
|---|---|
| Design root | https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=157-1769 |
| Login screen states | https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=431-24337 |
| Login error state | https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=431-24391 |
| Status pill notes | https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=276-22851 |
| Status catalogue / icons | https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=496-22505 |
| Tools and dashboard shortcuts | https://www.figma.com/design/IJzPzJ0P60XKYtlYXuVIXe/Ahmed--Copy-?node-id=496-22785 |

## App startup flow

### 1. Login

Design: login screen with email, password, password visibility, primary submit
button, and validation/error state.

APIs:

| Method | Path | Use |
|---|---|---|
| `POST` | `/auth/login` | Sign in and receive a Sanctum token |
| `POST` | `/auth/logout` | Revoke only the current device token |

Important details:

- The login identifier is email, not phone.
- Demo server credentials are `doctor@doctor1.test` / `password`.
- Wrong email and wrong password intentionally return the same
  `INVALID_CREDENTIALS` error.
- The app should store the token and send `Authorization: Bearer {token}` after
  login.

### 2. First authenticated load

Design: app shell, home screen, settings-dependent controls, status labels, and
role-based tools.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/bootstrap` | One launch call for clinic config, user, abilities, visit types, schedule, holidays |
| `GET` | `/home` | Home summary cards and alerts |

Recommended mobile behavior:

- Call `/bootstrap` after login and on app cold start.
- Cache visit types, weekly schedule, holidays, clinic settings, and abilities.
- Call `/home` whenever the home screen opens or returns to foreground.
- Use `abilities` to hide tools the signed-in user cannot access.

## Booking and calendar flow

### Calendar / booking list screen

Design: bookings page with date strip, filters, calendar counts, booking cards,
status pill, patient code, patient name, visit type, time, phone action, cancel
CTA, and status-change CTA.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/bookings/calendar?from=&to=&status=` | Date strip, day counts, and booking cards grouped by date |
| `GET` | `/bookings/{booking}` | Open details/edit for one card |

API-to-design mapping:

| Design field | API field |
|---|---|
| Day strip date | `days[].date.display`, `days[].day_number`, `days[].day_of_week.display` |
| Open/holiday state | `days[].is_open`, `days[].is_holiday` |
| Status filter counts | `days[].counts` |
| Booking card status pill | `booking.status.value`, `booking.status.display` |
| Patient code | `booking.patient.code` |
| Patient name | `booking.patient.name` |
| Visit type | `booking.visit_type.name` |
| Appointment time | `booking.start_time.display`, `booking.end_time.display` |
| Phone call action | `booking.patient.phone.value` |
| Buttons shown on card | `booking.available_actions` |

Notes:

- `status=` filters booking cards only. Day counts remain full-day counts.
- There is no `queue_position` in v1.
- Cards should be ordered by appointment time.

### New/edit booking flow

Design: patient phone/name form, visit type selector, date selector, slot picker,
returning-patient warning, name-conflict warning, force booking confirmation.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/slots?date=&visit_type_id=` | Available and unavailable start times for the selected date/type |
| `POST` | `/patients/lookup` | Recognise returning patient by phone |
| `POST` | `/bookings` | Create booking |
| `PUT` | `/bookings/{booking}` | Replace editable booking |

API-to-design mapping:

| Design behavior | API support |
|---|---|
| Slot picker changes by visit type | `/slots` requires `visit_type_id` |
| Grey unavailable slots | `/slots` returns unavailable slots with `is_available: false` |
| Closed day empty state | `/slots.closed_reason` |
| Returning patient prefill | `/patients/lookup.found` and `patient` |
| Name conflict prompt | `/patients/lookup.name_conflict` |
| Visit type mismatch warning | `/patients/lookup.visit_type_mismatch` |
| Overbooking confirmation | Create/edit with `force: true` after warning |
| Update stored patient name | `update_patient_name: true` |

Important details:

- Patients are matched by normalized phone only, never by name.
- `POST /bookings` creates the patient if the phone is new.
- Editing is allowed for `booked` and `arrived` only.
- Booking with `force: true` marks `is_overbooked`.

## Booking status and card actions

Design: six current statuses: booked, arrived, with doctor, done, cancelled, no
show. The status notes also say cancel can appear for any state except completed
or no-show; the API currently allows cancel for `booked`, `arrived`, and
`with_doctor`, and blocks `done`, `cancelled`, and `no_show`.

APIs:

| Method | Path | Use |
|---|---|---|
| `POST` | `/bookings/{booking}/status` | Move booking to the next allowed status |
| `POST` | `/bookings/{booking}/cancel` | Patient cancellation |
| `POST` | `/bookings/{booking}/message` | Send WhatsApp template for one booking |

Allowed status path:

```text
booked -> arrived -> with_doctor -> done
booked/arrived -> no_show
booked/arrived/with_doctor -> cancelled
```

Use `available_actions` from every booking card to decide what buttons to show.
Do not hardcode the action visibility only in Flutter.

## Settings and tools

Design: tools/dashboard shortcuts include holidays, retention, revenue,
notification settings, timing settings, emergency broadcast, and logout.

Implemented API coverage:

| Design tool | API |
|---|---|
| Visit type settings | `GET/POST/PUT/DELETE /visit-types` |
| Weekly working hours | `GET /schedule`, `PUT /schedule/{day}` |
| Holidays | `GET/POST/DELETE /holidays` |
| Timing settings | `PUT /settings/general` |
| Revenue | `GET /reports/revenue` |
| Retention | `GET /reports/retention` |
| Emergency broadcast / WhatsApp | `GET /message-templates`, `POST /broadcasts` |
| Logout | `POST /auth/logout` |

Not covered as a first-class API yet:

- Notification settings screen. The current messaging APIs support template
  sends through the log driver, but there is no user-editable notification
  settings endpoint yet.

## Holidays and postpone flow

Design: holiday settings, closed-day empty state, emergency postpone action,
call/rebooking list.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/holidays?include_past=` | Holiday list |
| `POST` | `/holidays` | Close a day |
| `DELETE` | `/holidays/{holiday}` | Remove holiday |
| `GET` | `/postpone/candidates?date=` | Patients affected by postponing |
| `POST` | `/postpone` | Cancel selected/all pending bookings for rebooking |
| `GET` | `/rebooking-list` | Patients awaiting a new appointment |
| `POST` | `/bookings/{booking}/contacted` | Mark patient as contacted |

Important details:

- Adding a holiday with bookings returns `HOLIDAY_HAS_BOOKINGS` unless
  `force: true` is sent.
- `POST /postpone` cancels selected bookings with `emergency` reason and frees
  the slots.
- Rebooking requires `POST /bookings` with `rebooking_for_booking_id`.

## Patients flow

Design: patient search, no-results empty state, patient profile/history screen,
patient code, visits summary, call action.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/patients?q=&per_page=` | Search/list patients |
| `GET` | `/patients/{patient}` | Patient file and visit history |

API-to-design mapping:

| Design field | API field |
|---|---|
| Patient code | `patient.code` |
| Search result name | `patient.name` |
| Search result phone | `patient.phone.display` masked |
| Patient profile phone | `patient.phone.display` unmasked |
| Visits count | `summary.visits_count` |
| No-show/cancelled count | `summary.no_show_count`, `summary.cancelled_count` |
| Visit history rows | `visits[]` |

Important details:

- Search matches name, code prefix, or last phone digits.
- Search result phones are masked; patient detail phones are unmasked.
- Visit history includes cancellations and no-shows.

## Reports flow

Design: revenue and retention tools/screens.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/reports/revenue` | Today/week/month revenue cards, comparison, daily chart |
| `GET` | `/reports/retention?period=` | Retention cohort metrics |

Revenue mapping:

| Design field | API field |
|---|---|
| Today/this week/this month cards | `periods.today`, `periods.this_week`, `periods.this_month` |
| Total revenue | `periods.*.total.display` |
| Completed visits | `periods.*.completed_visits` |
| Comparison arrow/color | `periods.*.comparison.direction` |
| Chart | `daily[]` |

Retention mapping:

| Design field | API field |
|---|---|
| Period selector | `available_periods[]` |
| Return rate | `return_rate` |
| Cohort size | `cohort_size` |
| Returned count | `returned_count` |
| First-visit-only count | `first_visit_only_count` |
| Maturing count | `maturing_count` |

Important details:

- Revenue is expected revenue from completed visits, not cash collection.
- Retention separates mature first-visit-only patients from recent patients who
  may still return.

## Messaging flow

Design: WhatsApp action on a card and emergency/bulk broadcast tool.

APIs:

| Method | Path | Use |
|---|---|---|
| `GET` | `/message-templates` | Active WhatsApp templates |
| `POST` | `/bookings/{booking}/message` | Send one template to one booking |
| `POST` | `/broadcasts` | Send one template to many bookings |

Current implementation note:

- v1 uses the log driver first. The API shape is ready for WhatsApp Cloud API,
  but no real WhatsApp send is required yet.

## Validation and error handling

The app should always branch on `error.code`, not translated `message`.

Common design-facing cases:

| UX case | Error code |
|---|---|
| Wrong login | `INVALID_CREDENTIALS` |
| Required/invalid field | `VALIDATION_FAILED` |
| Slot taken | `SLOT_UNAVAILABLE` |
| Clinic closed | `CLINIC_CLOSED_THAT_DAY` |
| Outside booking window | `SLOT_OUTSIDE_WINDOW` |
| Holiday already exists | `HOLIDAY_ALREADY_EXISTS` |
| Holiday has bookings | `HOLIDAY_HAS_BOOKINGS` |
| Invalid status change | `INVALID_STATUS_TRANSITION` |
| Booking cannot be edited | `BOOKING_NOT_EDITABLE` |
| Booking cannot be cancelled | `BOOKING_NOT_CANCELLABLE` |
| Nothing to postpone | `NOTHING_TO_POSTPONE` |
| Patient not found | `PATIENT_NOT_FOUND` |

## Integration checklist for Flutter

1. Import the generated Postman collection or generate a typed client from
   `openapi.yaml`.
2. Implement a single API envelope parser for `success`, `error`, and
   validation `fields`.
3. Store token after `/auth/login`; set `Accept-Language` on every request.
4. Use `/bootstrap` as the app launch source for clinic settings and abilities.
5. Use `/home` for home screen dynamic cards.
6. Use `/bookings/calendar` as the booking screen source, not separate queue
   endpoints.
7. Use each booking card's `available_actions` to show/hide buttons.
8. Use `/slots` after every date or visit-type change.
9. Call `/patients/lookup` after phone input is complete.
10. Use `force: true` only after explicit confirmation dialogs.
11. Use `/message-templates` before showing WhatsApp template choices.
12. Keep notification settings out of scope until we add a dedicated API.

## Current conclusion

The API surface is complete for the implemented v1 design areas: auth, launch,
home, booking calendar, booking create/edit, status actions, postpone/rebooking,
patients, holidays/settings, WhatsApp log-driver messaging, revenue, and
retention.

The only design area that looks intentionally deferred is notification settings.
Everything else has an API path and documented behavior.
