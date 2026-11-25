# Multi-branch: one doctor, several branches

Tickets: [ELA-16](https://linear.app/elayadah/issue/ELA-16) · [ELA-17](https://linear.app/elayadah/issue/ELA-17) ·
[ELA-18](https://linear.app/elayadah/issue/ELA-18) · [ELA-19](https://linear.app/elayadah/issue/ELA-19) ·
[ELA-20](https://linear.app/elayadah/issue/ELA-20) · [ELA-21](https://linear.app/elayadah/issue/ELA-21) ·
[ELA-22](https://linear.app/elayadah/issue/ELA-22)

> **Status: draft, waiting for approval.** The open questions are at the end.

## The model

The tickets say "doctor". In the code that is the **clinic**: the tenant that
owns patients, visit types and prices, the no-show block, reports and the
public `/{slug}` page. A **branch** is a place the doctor works from.

```
Clinic (= "the doctor" in the tickets)
 ├──< Branch          name, address, map, phone?, is_active, waiting_list_enabled
 │     ├──< ClinicSchedule (+ periods)   weekly hours, per branch
 │     ├──< Booking.branch_id            required
 │     ├──< WaitingListEntry.branch_id
 │     ├──< SlotHold.branch_id
 │     └──< QueueDisplay                 a TV, linked to one branch
 ├──< ClinicHoliday   branch_id NULL = all branches
 ├──< Patient         one record per patient, across all branches
 ├──< VisitType       same types and prices at every branch
 └──>< User ──>< Branch   (branch_user) + is_owner + permissions[]
```

Every existing clinic becomes **one branch**, with its data unchanged. While a
clinic has one branch, nothing new is shown (ELA-16/17/18/19/20). The one
exception is the WhatsApp branch line, which ELA-22 wants everywhere.

## What the code has today, and what the tickets assume

| Ticket assumes | Code today | Consequence |
|---|---|---|
| A doctor and secretaries with different rights | There is one role, `UserRole::CLINIC`, and every account has every ability. `isOwner()` and `isSecretary()` return the same thing. | We need a doctor/secretary distinction and permissions first (Step 1.2). |
| A "Team" section where the doctor assigns secretaries | Accounts are created and assigned only by the operator in Filament. | Team is a new screen. |
| A TV display per branch, on Android TV | There is no TV display at all. There is no mobile or TV code in this repo; they use `/api/v1`. | It is built from scratch. The TV client is outside this repo (Q2). |
| Holidays for a date range, for some or all branches | One date per row, clinic-wide. | The holiday model changes. |
| Emergency Delay grouped by branch, deselecting patients, a new time per branch | `DelayService` shifts every booking from one booking onward, for one clinic and one day. | This is a redesign, not a filter. |
| Bulk Cancellation | It does not exist. Bookings are cancelled one at a time. | New feature. |
| A branch line in 8 WhatsApp templates | The templates have no branch line. Confirmation has an address, `{{5}}`. | New template versions need Meta approval before release. |
| No-show block at doctor level | `no_show_block_enabled` and `ApplyNoShowBlocksCommand` work per clinic. | Already right. It only needs a test with two branches. |
| Phone lookup across all the doctor's patients | Patients are unique per `(clinic_id, phone)`. | Already right. |

## Rules that hold through every phase

- `clinic_id` stays on every row, and `branch_id` is added next to it. Reports
  and statistics that count clinic-wide keep working.
- The branch comes from the server: the account plus its last-used branch. If
  the client sends one, it is checked against `branch_user`, never trusted. This
  is the same rule as for `clinic_id` (SPEC §6.6).
- `ClinicDayLock` stays **doctor-wide** (per clinic and day), changed in Phase 4: hours never overlap across branches, and booked and held time is the doctor's at every branch, so one doctor can never be double-booked.
- The API stays backward compatible for the mobile app. Without an
  `X-Branch-Id` header, the account's last-used branch is used.
- Every phase adds tests with **two branches** and keeps single-branch tests
  green unchanged.
- Expand → move features over → contract. Each step is its own green PR.

## Phases

### Phase 1: Foundation and branch management (ELA-16)
Verified when: every clinic has one branch, every row has `branch_id` filled
in, and a doctor adds, edits and deactivates branches from Settings (web and
API).

#### Step 1.1: The branches table and the first branch (Size: S) ✅
- Acceptance criteria:
  - `branches` table: clinic_id, name (unique per clinic), address, lat/lng,
    phone, is_active, sort_order. Address, location and phone are **nullable
    and fall back to the clinic's** (`Branch::displayAddress()`,
    `coordinates()`, `contactPhone()`). The migrated branch copies nothing,
    so the clinic and the branch can't drift apart while the clinic row is
    still where they're edited.
  - The migration backfills one branch per clinic, named after the address's
    area (`App\Support\BranchName`: "عيادة الدقي"), falling back to the clinic
    name. `Clinic::created` gives every new clinic its first branch, whatever
    path creates it.
  - Changed from the first draft: **each table gets its `branch_id` in the
    phase whose code starts writing it** (schedules in 3, bookings in 4,
    waiting list in 6 …), backfilled to the clinic's first branch then. Added
    all at once, the columns would fill with NULLs between deploys. The
    per-branch settings columns (waiting list, …) follow the same rule.
    `users.last_branch_id` moves to 2.1.
- Verification command: `php artisan test --filter=BranchBackfill` and `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoClinicSeeder`
- Files likely touched: `database/migrations/2026_11_*`, `app/Models/Branch.php`, `database/factories/BranchFactory.php`, `app/Models/Clinic.php`, `tests/Concerns/InteractsWithClinic.php`
- Test seams: a migration test on a seeded clinic; the `InteractsWithClinic` helper creates the branch.
- Blocked by: none

#### Step 1.2: Doctor and secretary, and permissions (Size: M) ✅
- Acceptance criteria:
  - `users.is_owner` (the doctor) and `users.permissions` (JSON:
    `branches.manage`, `schedule.manage`). Backfill, so that no existing
    account loses anything: the login linked to the doctor is the owner; a
    clinic with no such login shares one account, so every account of it is
    an owner; any other account is an assistant **with `schedule.manage`**.
  - General settings and visit types stay open to every account, as today.
    Whether an assistant should lose them is a product question for later,
    not part of this change.
  - Hours and holidays: reading is open to everyone (API `GET`); changing
    them needs `schedule.manage` (API and web). `/me` and `/bootstrap` list
    the abilities the account can actually use, plus `user.is_owner`.
  - `User::can('branches.manage', $branch)` is true for the owner, or for a
    secretary who has the permission and is assigned to that branch.
  - `ClinicComponent::requireAbility` and the API's `EnsureAbility` use it.
- Verification command: `php artisan test --filter=Permission`
- Files likely touched: `app/Models/User.php`, `app/Enums/UserRole.php`, `app/Enums/StaffPermission.php` (new), `app/Http/Middleware/EnsureAbility.php`, `app/Livewire/App/ClinicComponent.php`, Filament UserForm
- Test seams: unit tests on `User::can`; one feature test per guarded screen.
- Blocked by: 1.1

#### Step 1.3: The Branches settings screen and API (Size: M) ✅
- Acceptance criteria:
  - Settings → Branches: a list with Active/Inactive, and one add/edit form.
    The location comes from a pasted Google Maps link or typed latitude and
    longitude. A map pin picker (needs a map library) and short
    `maps.app.goo.gl` links are not done: the screen asks for the full link.
  - The first branch reads and writes the **clinic's** address and pin, so
    the panel, the public page and the messages keep one source until their
    readers move to the branch (Phases 4 and 7).
  - Deactivating says that upcoming bookings stay active. **The count of
    them moves to Phase 4**, when bookings get `branch_id`; until then there
    is only one branch, and it can't be deactivated. The last active branch cannot be deactivated. Reactivating is
    allowed.
  - **Add** is behind `CLINIC_MULTI_BRANCH` (off) until Phases 3–4 have
    shipped. Before then a second branch would have no hours of its own and
    no bookings. With it off, the screen shows the one branch and lets the
    doctor edit it.
  - A branch without working hours is not bookable. That rule is enforced in
    `SlotAvailabilityService`, which already returns no slots without
    periods; a test proves it.
- Verification command: `php artisan test tests/Feature/Settings --filter=Branch`
- Files likely touched: `app/Livewire/App/Settings/Branches.php`, `resources/views/livewire/app/settings/branches.blade.php`, `app/Services/V1/Branches/BranchService.php`, `app/Http/Controllers/Api/V1/Branches/*`, `routes/{web,api}.php`, `resources/views/app/partials/settings-nav.blade.php`, `lang/{ar,en}/*.php`, `docs/api/v1/openapi.yaml`
- Test seams: `BranchService` feature tests, a Livewire test for the screen, API controller tests.
- Blocked by: 1.2

### Phase 2: Team access and branch switching (ELA-17)
Verified when: a secretary assigned only to branch B can neither see nor
change anything at A (web and API), and the doctor switches branches in one
tap.

#### Step 2.1: Resolving the active branch (Size: M) ✅
- Acceptance criteria:
  - `branch_user` pivot. `User::accessibleBranches()`: all active branches for
    the owner, the assigned ones otherwise.
  - `EnsureClinicSession`, `ResolveClinic` and `ClinicComponent::branch()`
    resolve the branch in this order: the requested one if allowed, else
    `last_branch_id` if still allowed, else the first allowed one. If none is
    allowed, the account is signed out with a clear message.
  - The resolution runs on every request, so removing an account from a
    branch takes effect immediately.
  - As built: `App\Services\V1\Branches\ActiveBranch`. The web keeps the
    picked branch in the session (so two browsers on one shared login don't
    pull each other around); the API reads `X-Branch-Id` and **echoes the
    branch it used in the response's `X-Branch-Id`**. `users.last_branch_id`
    is what both open on. Inactive branches stay usable: their existing
    bookings still need running.
  - The Phase 1 branch endpoints now only show and change the account's own
    branches; a member who adds a branch is assigned to it.
- Verification command: `php artisan test tests/Feature/Web tests/Feature/Api --filter=Branch`
- Files likely touched: `app/Models/User.php`, `app/Http/Middleware/{EnsureClinicSession,ResolveClinic}.php`, `app/Livewire/App/ClinicComponent.php`, `app/Enums/AuthErrorCode.php`, `app/Services/Results/V1/Auth/AuthenticatedUserResult.php` (adds branches to `/me`)
- Test seams: middleware feature tests: allowed, not allowed, removed while signed in.
- Blocked by: 1.2

#### Step 2.2: The switcher and Settings → Team (Size: M) ✅
- Acceptance criteria:
  - A branch switcher in the header, only when more than one branch is
    accessible. Switching saves `last_branch_id` and resets the screen's day
    and filters.
  - Settings → Team (doctor only, `team.manage`, never grantable): every
    member, with their branches (at least one) and extra permissions. Web
    screen plus `GET /team` and `PUT /team/{member}`. Accounts are still
    created by the operator, whose Filament form now also sets branches.
  - **Not done:** "Doctor sees an 'All branches' view where relevant (e.g.
    stats)" lands with the screens that have one: statistics in Phase 8.
- Verification command: `php artisan test --filter='BranchSwitcher|TeamSettings'`
- Files likely touched: `resources/views/app/partials/navigation.blade.php`, `app/Livewire/App/BranchSwitcher.php`, `app/Livewire/App/Settings/Team.php`, Team API controllers
- Test seams: Livewire tests.
- Blocked by: 2.1

### Phase 3: Working hours and holidays per branch (ELA-20)

As built: the settings side is per branch (screens, API, rules). **The
booking side still reads the clinic's first branch** (`Clinic::scheduleFor`,
`$clinic->holidays()`): with `CLINIC_MULTI_BRANCH` off a clinic has that one
branch, so the answers are the same. Phase 4 must move every one of those
readers (SlotAvailabilityService, PatientBookingService, BookingDaysService,
BookingCalendarService, WaitingListService, the reminder commands,
`Clinic::currentDay`, reports and statistics, the landing page) to the
booking's branch **before the switch can go on**. "Two branches give two sets
of slots" is verified there. Visit-length days stay doctor-level: ELA-20
doesn't mention them.
This comes before booking because slot availability reads it.
Verified when: Sat 1–4 Gedeila and 5–10 Al-Tur'a give two separate sets of
slots, an overlap is refused, and a holiday for one branch closes only that
branch.

#### Step 3.1: Weekly hours per branch (Size: M) ✅
- Acceptance criteria:
  - The unique key becomes `(branch_id, day_of_week)`. A day with no periods
    means the branch is off that day.
  - Saving is refused when it overlaps another branch's period on the same
    day, and the conflict is shown. A gap under `config('clinic.branches.min_gap_minutes')`
    (30) gets a warning only.
  - Saving shows how many existing bookings fall outside the new hours. The
    bookings are not changed.
  - Settings → Hours has the branch switcher, plus a weekly overview of all
    branches, color-coded. Editing needs `schedule.manage`.
- Verification command: `php artisan test tests/Feature/Settings tests/Feature/Booking`
- Files likely touched: `app/Models/{Branch,ClinicSchedule}.php`, `app/Livewire/App/Settings/Hours.php`, `app/Services/V1/Settings/ScheduleService.php`, `app/Services/V1/Booking/{SlotAvailabilityService,BookingDaysService,DayAvailability}.php`, Settings API controllers
- Test seams: `SlotAvailabilityService` with two branches; a `ScheduleService` overlap test.
- Blocked by: 2.1

#### Step 3.2: Holidays for a date range and a branch scope (Size: M) ✅
- Acceptance criteria:
  - The form starts with scope: "All branches" (the default) or specific
    branches, then a date or a range. "All" is stored as `branch_id NULL`
    and also covers branches added later. A range is stored as one row per
    date, linked by `group_id`.
  - Saving shows how many bookings are affected. Nothing is cancelled
    automatically.
  - Affected slots disappear from the secretary's booking screen and the
    patient page.
- Verification command: `php artisan test tests/Feature/Settings --filter=Holiday`
- Files likely touched: migration on `clinic_holidays`, `app/Models/ClinicHoliday.php`, `app/Livewire/App/Settings/Holidays.php`, `Create/Delete/ListHolidayController`, `app/Services/V1/Booking/DayAvailability.php`
- Test seams: `DayAvailability` with a branch holiday and an all-branches holiday.
- Blocked by: 3.1

### Phase 4: Booking with branch selection (ELA-18)

As built: `bookings.branch_id` and `slot_holds.branch_id` (backfilled to the
first branch; a creating-hook fills any row written without one). Slots come
from the branch's hours and holidays; taken and held time stays doctor-wide.
Staff book at the header's branch or pick another of theirs; the API uses
`X-Branch-Id`; edits stay at the booking's branch; a deactivated branch takes
no new booking, emergencies included. The patient page and the doctor page
list bookable branches (active, with hours), with `?b=<id>` links.
`Clinic::currentDay()` follows last night's evening at any branch.

**Still clinic-wide, each moved by its own phase, and the reason
`CLINIC_MULTI_BRANCH` stays off until Phase 8:** the queue, tracking and the
Turn Approaching alert (Phase 5), the waiting list (Phase 6), the daily
reminder and other WhatsApp (Phase 7), reports and statistics (Phase 8).
Verified when: every new booking has a branch, the same time at two branches
can be booked twice, and a patient picks a branch before the day.

#### Step 4.1: Staff booking per branch (Size: M)
- Acceptance criteria:
  - `BookingService`, `SlotHoldService` and `ClinicDayLock` take a `Branch`.
    `branch_id` is required on every new booking.
  - NewBooking opens on the current branch, with a chip limited to the
    accessible branches. The confirmation shows the branch. Postpone and
    Rebooking stay on the booking's branch.
  - The API booking endpoints accept an optional `branch_id`, checked against
    access.
- Verification command: `php artisan test tests/Feature/Booking tests/Feature/Queue`
- Files likely touched: `app/Services/V1/Booking/*`, `app/Livewire/App/{NewBooking,Postpone,Rebooking}.php`, `app/DTOs/V1/**`, Booking API controllers, `openapi.yaml`
- Test seams: `BookingService` feature tests, including the same slot at two branches.
- Blocked by: 3.2

#### Step 4.2: The patient page picks a branch (Size: M)
- Acceptance criteria:
  - `/{slug}` shows branch cards above the calendar: name, address, days and
    hours, and the location. The flow is Branch → Name/Phone → OTP → Visit
    type/Day/Slot. Switching branch resets the day and slot. The branch step
    is skipped when only one is active.
  - `/{slug}?b=<branch>` opens with that branch preselected. The final
    confirmation shows the branch name and address.
  - The cancel cutoff and the no-show block still apply.
- Verification command: `php artisan test tests/Feature/SelfBooking tests/Feature/Web`
- Files likely touched: `app/Livewire/Patient/BookVisit.php`, `resources/views/landing/**`, `app/Http/Controllers/Web/DoctorLandingController.php`, `app/Services/V1/Booking/PatientBookingService.php`
- Test seams: BookVisit Livewire tests.
- Blocked by: 4.1

### Phase 5: Queue, patient record and the TV display (ELA-19)

As built: today's line, queue positions (tracking page, "turns before you"),
the queue screen and search, the call list, postpone candidates and the
emergency delay are per branch; the Turn Approaching alert works each branch's
line, holidays and start of day. The tracking page names the branch and maps
it. The patient record is doctor-wide with a branch tag per visit (web and
API); no-shows already count across branches. **TV display** (decided without
an answer to Q2, on the recommendation): a server-rendered page at
`/tv/{token}` that reloads itself every 15 s, one branch per display, codes
and first names only, an idle screen outside the branch's hours; an Android
TV app opens that URL. Settings → Displays (`branches.manage`, within one's
branches) adds, renames, moves, re-links and removes them.
Verified when: no booking ever shows in another branch's queue, on its
tracking page or on its TV, and the patient record tags every visit with its
branch.

#### Step 5.1: Queue and tracking per branch (Size: M)
- Acceptance criteria:
  - `QueueService`, `QueueOrder`, `QueuePositionService`, `QueueSearchService`,
    `TrackingDelayService`, `EmergencyController` and the Turn Approaching
    job filter by branch. All 6 states work per branch.
  - The queue header shows the branch next to the switcher. The tracking
    page shows the branch name and address.
- Verification command: `php artisan test tests/Feature/Queue`
- Files likely touched: `app/Services/V1/Queue/*`, `app/Livewire/App/Queue.php`, `resources/views/tracking/**`, the Turn Approaching command/job
- Test seams: `QueuePositionService` with two branches on one day.
- Blocked by: 4.1

#### Step 5.2: Patient record and no-show across branches (Size: S)
- Acceptance criteria:
  - A branch tag on every visit in PatientProfile, for every secretary of the
    doctor.
  - A test proves no-shows count across branches and the block applies at
    every branch.
- Verification command: `php artisan test tests/Feature/Patient --filter='Branch|NoShow'`
- Files likely touched: `app/Livewire/App/PatientProfile.php`, its view, `ShowPatientController`, `tests/Feature/Patient/*`
- Test seams: PatientProfile Livewire test; `ApplyNoShowBlocksCommand` test.
- Blocked by: 4.1

#### Step 5.3: The TV display (Size: L, split after Q2)
- Acceptance criteria:
  - A `queue_displays` table (clinic_id, branch_id, name, a token that can be
    rotated). Settings → Displays lists them. Changing a display's branch
    needs `branches.manage`.
  - A display feed (JSON and/or a page, per Q2): today's queue for its
    branch, with the branch name at the top. Outside hours or on a day off
    it shows an idle screen.
- Verification command: `php artisan test --filter=QueueDisplay`
- Files likely touched: per Q2
- Blocked by: 5.1

### Phase 6: Waiting list per branch (ELA-21)
Verified when: with the list on only at branch 3, the join option shows only
at branch 3, and a cancellation at branch 3 offers only branch 3's list for
that day.

#### Step 6.1 (Size: M)
- Acceptance criteria:
  - `branches.waiting_list_enabled` (off for new branches, carried over for
    the migrated branch). Entries have branch and date. The per-day cap is
    per branch.
  - The join button is hidden where the list is off. The screen shows branch
    and date.
  - Turning it off with patients waiting shows the count and asks to
    confirm. Nobody is removed. "No Slot Opened" still goes out at the end
    of the day.
- Verification command: `php artisan test tests/Feature/WaitingList`
- Files likely touched: `app/Services/V1/WaitingList/*`, `app/Livewire/App/WaitingList.php`, the end-of-day command, WaitingList API controllers, Branch settings form
- Test seams: existing WaitingList feature tests, with a second branch.
- Blocked by: 4.1

### Phase 7: WhatsApp, delay and cancellation per branch (ELA-22)
Verified when: every message carries the booked branch's name, address and
phone, and delay, cancellation and announcement reach only the chosen
branches.

#### Step 7.1: Branch line, address, phone and links in the templates (Size: M)
- Acceptance criteria:
  - The texts are drafted in `docs/whatsapp/multi-branch-templates.md`
    (approved 2026-11-25): 8 new versions with `🏥 العيادة`. They are
    **submitted to Meta and approved before the switch is turned on** (an
    outside action).
  - `TemplatePayloadResolver` fills in the branch name, address and phone
    (with fallback). The "book again" links preselect the booking's branch;
    Feedback Request uses the last-visited branch.
  - A new switch, `CLINIC_WHATSAPP_BRANCH_TEMPLATES` (off by default), selects
    the new names and value lists for all 8 at once. There is no runtime
    version switch today; a version change is a seeder + resolver deploy.
- Verification command: `php artisan test tests/Feature/Messaging`
- Files likely touched: `app/Services/V1/Messaging/TemplatePayloadResolver.php`, `database/seeders/MessageTemplateSeeder.php`, `docs/whatsapp/current_templates.md`, `config/clinic.php`
- Test seams: one payload test per template.
- Blocked by: 4.2

#### Step 7.2: Emergency Delay with a branch scope (Size: M)
- Acceptance criteria:
  - Scope (branch chips plus "All branches" = the user's accessible branches)
    → day → patients grouped by branch, any of them can be deselected → a
    new time per branch → a preview → send.
  - Patients already with the doctor are never included. The current replay
    protection and lock still apply, per branch.
- Verification command: `php artisan test --filter=Delay`
- Files likely touched: `app/Services/V1/Queue/DelayService.php`, `DelayOutcome.php`, `app/Livewire/App/Delay.php`, `DelayOptionsController`, a new Delay API request
- Blocked by: 5.1

#### Step 7.3: Bulk Cancellation with a branch scope (Size: M)
Today the Cancellation Apology broadcast cancels every pending booking on a
day. This step adds the branch scope, several days, and deselecting patients.
- Acceptance criteria:
  - Scope → day(s) → grouped list with deselect → a summary per branch
    ("Cancel 12: Gedeila 5 · Al-Tur'a 7") → one confirmation.
  - Bookings become Cancelled (not no-show), and Cancellation Apology goes
    out with the branch preselected in its link.
- Verification command: `php artisan test --filter=BulkCancel`
- Files likely touched: `app/Services/V1/Queue/BulkCancellationService.php`, `app/Livewire/App/BulkCancel.php`, an API controller, routes, nav
- Blocked by: 5.1

#### Step 7.4: Clinic Announcement with a branch scope (Size: S)
- Acceptance criteria:
  - The scope selector on Messages. The name variable is doctor + branch, or
    the doctor alone for "All branches".
  - Recipients are the patients with bookings at the chosen branches (the
    current recipient rule, filtered by branch).
- Verification command: `php artisan test --filter=Broadcast`
- Files likely touched: `app/Livewire/App/Messages.php`, `CreateBroadcastController`, `WhatsAppMessagingService`
- Blocked by: 7.1

### Phase 8: "All branches" statistics and the contract (Size: M)
- Acceptance criteria:
  - The reports and Reviews get a branch filter for the doctor, with "All
    branches" as the default.
  - `branch_id` becomes NOT NULL where the tickets require it. The address
    and waiting-list columns are dropped from `clinics` once a grep shows
    nothing reads them.
  - `openapi.yaml`, the Postman collection and `docs/onboarding.md` are
    updated, and the demo seeder gets 2 branches.
- Verification command: `php artisan test` and `php artisan test -c phpunit.mysql.xml`
- Blocked by: 5–7

## Branches

All of multi-branch collects on one integration branch,
**`feature/multi-branch`**, cut from `develop`. Each phase has its own branch
and its own PR **into `feature/multi-branch`**. When every phase has merged
there, one PR takes `feature/multi-branch` into `develop`.

A phase is built on top of the previous phase's branch while that PR is
still open, and its PR targets that branch. Once the earlier PR merges, the
later one is pointed at `feature/multi-branch`.

| Phase | Ticket | Branch |
| --- | --- | --- |
| 1 Branch management | ELA-16 | `feature/ela-16-multi-branch-18-branch-management` |
| 2 Team access and switching | ELA-17 | `feature/ela-17-multi-branch-28-team-access-branch` |
| 3 Hours and holidays | ELA-20 | `feature/ela-20-multi-branch-58-working-hours-holidays` |
| 4 Booking | ELA-18 | `feature/ela-18-multi-branch-38-booking-with-branch` |
| 5 Queue, record and TV | ELA-19 | `feature/ela-19-multi-branch-48-queue-tv-display` |
| 6 Waiting list | ELA-21 | `feature/ela-21-multi-branch-68-waiting-list-per` |
| 7 WhatsApp, delay and cancellation | ELA-22 | `feature/ela-22-multi-branch-78-whatsapp-messages` |
| 8 Statistics and contract | none | `feature/multi-branch-8-contract-and-stats` |

## Decision log
| Decision | Chosen option | Why |
| --- | --- | --- |
| Double-booking lock | Doctor-wide, per clinic and day (Phase 4) | Hours never overlap across branches, so a per-branch lock buys nothing; the doctor is one person. |
| What a branch is | A new `branches` table under Clinic | The tickets keep patients, prices, the no-show block and the sender at doctor level, and that is `Clinic` today. |
| "All branches" holiday | `branch_id NULL`, also covering future branches | Matches the "All branches" default without a pivot. |
| Holiday range | One row per date plus `group_id` | Availability keeps its per-date lookup. A range can be deleted as one. |
| Branch on API calls | `X-Branch-Id`, optional, checked; the default is the last-used branch | The mobile app keeps working before it learns about branches. |
| Phone fallback | `branches.phone ?? clinics.phone` | "The doctor's main booking number" is `clinics.phone`. |
| Template rollout | 8 new versions (`docs/whatsapp/multi-branch-templates.md`), the branch line for every clinic, behind one all-or-nothing switch | ELA-22 wants one version. Meta approval can't block merging, and half the templates showing the branch would confuse patients. |

## Not yet specified
- Step 5.3: the shape of the TV client (Q2) decides the files.
- ELA-17 says permissions are "first: Manage branches; also Manage schedule".
  Are more expected later? The enum leaves room for them.
- "8/8": the titles count to 8 but only 7 tickets exist. Is one missing?

## Always / Never
- Always: single-branch clinics see no change (except the WhatsApp branch
  line). The branch is checked on the server. Every phase has tests with two
  branches.
- Never: cancelling or moving bookings automatically (deactivating, changing
  hours, adding a holiday), splitting patients per branch, per-branch prices
  or visit types, or a WhatsApp number per branch.

## Progress
| Phase | Status |
| --- | --- |
| 1 Foundation and branch management | merged (#16) |
| 2 Team access and switching | merged (#17) |
| 3 Hours and holidays | merged (#18) |
| 4 Booking | merged (#19) |
| 5 Queue, record and TV | PR open |
| 6 Waiting list | pending |
| 7 WhatsApp, delay and cancellation | pending |
| 8 Statistics and contract | pending |
