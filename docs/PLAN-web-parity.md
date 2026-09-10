# Bringing `/app` level with the mobile API

The clinic web app does bookings, the queue and status changes. The API does
those and five more things. This plan closes the gap.

---

## The rule this plan is built on

**No API file changes.** Every capability below already exists as a service
that takes explicit parameters and knows nothing about transport. `/app` calls
the same ones the API controllers call.

```
Livewire component ─┐
                    ├─→ the same service ─→ the same rules
API controller     ─┘
```

Components orchestrate and render. Every business rule stays in the service.
The first `if` deciding a clinic rule inside a component is a bug, because the
mobile client would not see it.

**If a bug turns up in a service**, it is fixed once, with a regression test
written before the fix — and both clients get the fix together. That is the
only circumstance in which shared code is touched.

### The guard, run after every phase

```bash
git stash && php artisan test tests/Feature/Api && git stash pop
```

`tests/Feature/Api` is the mobile contract, 222 tests. It must stay green
without being edited. If a phase requires editing an API test, the phase is
wrong.

---

## Phase 0 — the shell — **done**

The two screens that exist today are standalone; there is no navigation
between them. Everything below needs somewhere to live.

- **App navigation** — queue · new booking · patients · postpone · reports ·
  messages · settings. Collapses to a bottom bar on a phone.
- **`ClinicComponent::requireAbility()`** — mirrors the API's `EnsureAbility`
  middleware. `UserRole::CLINIC` holds every ability today, so nothing is
  hidden yet; the checks exist so that stops being true safely.
- **A shared table partial** — search box, empty state, pagination. Five
  screens need one.

*Tests:* navigation renders for a clinic account; a user without an ability
cannot reach the screen that needs it.

---

## Phase 1 — Patients — **done**

The highest-value gap: the secretary looks a patient up constantly.

| Screen | Service |
|---|---|
| `/app/patients` — search by name, code or phone | `PatientSearchService::search` |
| `/app/patients/{id}` — profile, visit history, summary | `find`, `history`, `summary` |

- Search matches the way she types — a name fragment, an ID code, or the tail
  of a phone number. That behaviour is already in the service.
- History shows each visit's status and its **snapshotted** price and
  duration, never the visit type's current values.
- Price column gated on `prices.view`.
- "Book again" hands the patient to the new-booking screen pre-selected.

*Tests:* search finds by all three forms; another clinic's patient is a 404;
history is newest first; snapshots are shown, not live values; a caller
without `prices.view` sees no money.

---

## Phase 2 — Settings — **done**

Four screens under `/app/settings`.

| Screen | Service | DTO |
|---|---|---|
| General | `ClinicSettingsService::updateGeneral` | `GeneralSettingsData` |
| Working hours | `ScheduleService::week`, `updateDay` | `ScheduleDayData`, `SchedulePeriodData` |
| Visit types | `VisitTypeService::list/create/update/hide` | `VisitTypeData` |
| Holidays | `HolidayService::list/create/delete` | — |

Three things the services already enforce, which the screens must surface
rather than re-implement:

- A visit type is **hidden, never deleted** — bookings reference it for ever.
- Adding a holiday on a day that already has bookings needs `force`, and the
  screen must say what it is about to affect before asking again.
- Changing a price does not rewrite history; existing bookings keep theirs.

*Tests:* each screen saves through its service; hiding keeps past bookings
intact; a holiday over a booked day warns before forcing; price changes leave
old bookings untouched.

---

## Phase 3 — Postpone and the rebooking list — **done**

The clinic's worst day, and the flow with the most side effects.

| Screen | Service |
|---|---|
| `/app/postpone` — pick a day, see who is affected, postpone | `QueueService::postponeCandidates`, `PostponeService::postpone` |
| `/app/rebooking` — patients still owed a new appointment | `QueueService::awaitingRebooking`, `PostponeService::markContacted` |

- Postponing **cancels** bookings with reason `emergency` and frees their
  slots. The screen must show exactly who and how many, and confirm.
- A postponed patient stays on the rebooking list until a replacement booking
  is linked to the original.
- Booking a replacement needs `rebookingForBookingId` passed through — the DTO
  already carries it; the web's new-booking screen needs to accept it. That is
  a change to the component, not to `BookingService`.
- Optionally sends `day_cancelled`, which itself cancels — see Phase 5.

*Tests:* postponing frees slots and lists the right patients; a rebooked
patient leaves the list; the count matches the queue screen.

---

## Phase 4 — Revenue and retention — **done**

| Screen | Service |
|---|---|
| `/app/reports` — period selector, revenue, retention | `RevenueService`, `RetentionService`, `ReportPeriod` |

- Revenue: totals, a daily series with no gaps, and the comparison against the
  previous equivalent period. All of it already computed.
- The business week starts **Saturday** — the service knows; the screen must
  not assume otherwise.
- Retention: visits in the period, total patients, who returned.
- Gated on `reports.view`, money on `prices.view`.

*Tests:* each period returns what the API returns for the same clinic and
period; another clinic's money never appears; a caller without the ability is
refused.

---

## Phase 5 — Broadcast messages — **done**

Last, because it is the one that reaches patients.

| Screen | Service |
|---|---|
| `/app/messages` — pick a template, pick a day or specific bookings, send | `WhatsAppMessagingService::templates`, `broadcast` |

- Only templates flagged `is_broadcast` are offered. `booking_confirmed` and
  `visit_completed` must never appear here.
- **`day_cancelled` cancels every pending booking on the day it is sent.** The
  screen must state that plainly and confirm, showing the count first.
- Patients without WhatsApp consent are skipped, and the result says how many
  and why.

*Tests:* per-booking templates are not offered; `day_cancelled` cancels and
reports the count; opted-out patients are skipped and named as skipped.

---

## Order, and why

1. **Shell** — nothing else is reachable without it
2. **Patients** — used every hour of every day
3. **Settings** — a clinic cannot be set up without it
4. **Postpone** — rare but painful, and touches money and slots
5. **Reports** — valuable, but nobody is blocked without it
6. **Messages** — last, because a mistake here reaches patients

Phases 1–5 are independent of each other. Each is shippable on its own.

---

## What this plan does not do

- Change any controller, request, result or route under `api/v1`
- Change any service's behaviour, except to fix a bug found in passing
- Add a business rule to a Livewire component

---

## Where the risk actually is

**Not in the services** — they are already shared and covered by 222 API
tests.

**In the side effects.** Three actions here change data beyond the screen that
triggered them: postponing cancels bookings, `day_cancelled` cancels bookings,
and hiding a visit type changes what can be booked tomorrow. Each needs to say
what it is about to do, with counts, before it does it.

**And in ability drift.** `UserRole::CLINIC` currently holds every ability, so
a missing check would not fail a test today, and would fail silently the day
roles are split. Every screen gets its check now, while it is cheap.
