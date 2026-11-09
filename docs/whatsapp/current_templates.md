# WhatsApp templates in use

Every template the app sends, with its Meta name, its exact text, and the
values it is sent with, in order. The value lists are the contract: Meta
matches them by position and rejects a message whose count is wrong
(`#132000 Number of parameters does not match`).

The code that fills each template in is `TemplatePayloadResolver`. Our copy of
each text is in `MessageTemplateSeeder`. Meta renders the approved text itself,
so our copy is only for the dashboard and for support.

| | |
|---|---|
| Business | Elayadah |
| Sender | +20 12 83176126 (VERIFIED, quality GREEN) |
| Phone number ID | `1287295631138307` |
| Graph version | `v25.0` |
| Domain | `https://elayadah.com` |

Every URL button is registered as `https://elayadah.com/{{1}}`, so the button
value is a **whole path**, not just a token: `booking/<token>`.

All templates are **UTILITY** and **Arabic (`ar`)**.

---

## Status

Seven templates were rewritten from the Nov 2, 2026 spec and submitted on
2026-11-05. **They are in review.** The app already sends their new names and
shapes, so **don't deploy until all seven are approved**. Until then Meta
refuses every send to them. Once approved, read each one back from the Graph
API (name, value count, button order, button URL) before deploying.

| # | Meta name | Our key | Status |
|---|---|---|---|
| 1 | `appointment_booking_confirmation_v4` | `booking_confirmed` | In review (replaces `_v3`) |
| 2 | `visit_guidelines` | `visit_guidelines` | In review (new) |
| 3 | `appointment_reminder_today_v2` | `appointment_reminder_today` | In review (replaces the unversioned one) |
| 4 | `appointment_reminder_v2` | `appointment_reminder` | In review (replaces the unversioned one) |
| 5 | `appointment_delayed_v2` | `appointment_delayed` | In review (replaces the unversioned one) |
| 6 | `booking_cancellation_v2` | `day_cancelled` | In review (replaces the unversioned one) |
| 7 | `appointment_rating_v4` | `visit_completed` | In review (replaces `_v3`) |
| 8 | `waiting_list_joined` | `waiting_list_joined` | Unchanged |
| 9 | `waiting_list_no_slot_opened_v3` | `waiting_list_no_slot_opened` | Unchanged; the spec's new wording isn't submitted yet |
| 10 | `waiting_list_slot_booked` | `waiting_list_slot_booked` | Unchanged |
| 11 | `clinic_announcement_v2` | `clinic_announcement` | Unchanged |
| 12 | `clinic_report` | `clinic_report` | Unchanged; goes to the doctor, not patients |

`appointment_earlier` was drafted but never submitted. It's seeded inactive,
so it can't be sent.

**How dates and times read.** Dates are `15 سبتمبر 2026`, times
`06:30 مساءً`. An emergency booking has no time, so it says
`بدون ميعاد محدد` instead of sending an empty value, which Meta rejects. A
visit after midnight on an evening that runs past it (01:00 on Monday's
19:00–03:00) keeps the evening's date, and its time reads
`01:00 بعد منتصف الليل`.

---

## 1. Booking Confirmation: `appointment_booking_confirmation_v4`

Sent the moment a booking is taken, on the mobile app and the web alike.
Template ID `2168195491242678`.

```
👋 أهلاً {{1}}،
تم تأكيد حجزك بنجاح ✅

📅 التاريخ: {{2}}
⏰ الموعد: {{3}}
👨‍⚕️ الطبيب: {{4}}
📌 العنوان: {{5}}

⏳ الموعد ممكن يتأخر لو فيه حالة طارئة، وهنبلغك بأي تغيير.

📋 تعليمات الزيارة هتوصلك في الرسالة الجاية.
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Date | 15 سبتمبر 2026 |
| `{{3}}` | Time | 06:30 مساءً |
| `{{4}}` | Doctor, with one `د.` | د. سارة النجار |
| `{{5}}` | Clinic address | 12 شارع مصدق، الدقي |

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>`

Changed from `_v3`: the arrive-early minutes (`{{6}}`) moved to Visit
Guidelines, and an emergency-delay line was added.

---

## 2. Visit Guidelines: `visit_guidelines` (new)

Sent **10 seconds after** the confirmation, so it arrives second. The
confirmation announces it. Template ID `4629394283996329`.

Not sent:
- for an emergency booking (it has no time),
- for a slot booked from the waiting list,
- when the confirmation itself wasn't sent.

```
📋 تعليمات الزيارة:

🕐 احضر قبل موعدك بـ {{1}} دقيقة. لو اتأخرت، هتدخل بعد الحالات المحجوزة الموجودة.

📞 مش هتقدر تيجي؟ ألغِ الحجز بأسرع وقت علشان نقدر ندي الميعاد لمريض محتاجه. الغياب بدون إلغاء يوقف الحجز من التطبيق.

🚑 الحالات الطارئة ليها الأولوية، فممكن عدد اللي قبلك يزيد.

🔗 تابع دورك أول بأول من هنا: {{2}}

شكرًا لتعاونك 🙏
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Arrive-early minutes, **a bare number** (the text already says `دقيقة`). From the clinic's `patient_arrival_lead_minutes`. | 30 |
| `{{2}}` | Tracking link, written out in full in the body | https://elayadah.com/booking/AbC123 |

**Button:** quick reply `الغاء الحجز` (approved without the hamza). It takes
no value.

A tap on it is handled like the reminder's Cancel (`WhatsAppReplyHandler`).
Outside the clinic's cancel cutoff, the booking is cancelled and the patient
gets the cancelled reply. Inside the cutoff, the cancel is refused and the
patient is told to call the clinic.

---

## 3. Reminder + Confirm / Cancel: `appointment_reminder_today_v2`

Sent by `clinic:send-daily-reminders` on the visit day, at the clinic's
reminder time. Only for bookings made at least 12 hours before that time
(`daily_reminder_min_hours`), and only before the cancel cutoff.
Template ID `1035505822923857`.

```
👋 أهلاً {{1}}،
يُرجى تأكيد حضورك لموعدك ⏰

📅 التاريخ: {{2}}
⏰ الموعد: {{3}}
👨‍⚕️ الطبيب: {{4}}

ولو الظروف اتغيرت، الإلغاء بدري بيدي الفرصة لمريض تاني محتاج الميعاد.
```

| | Value |
|---|---|
| `{{1}}` | Patient name |
| `{{2}}` | Date |
| `{{3}}` | Time |
| `{{4}}` | Doctor, with one `د.` |

**Buttons:** quick replies `تأكيد الحضور` · `إلغاء الحجز`. No link: the
tracking link comes in the reply after Confirm.

**Replies after a tap.** These are free text, not templates: the tap opens
WhatsApp's 24-hour window. The texts are in `lang/ar/messages.php` →
`reminder_follow_up`:
- **Confirm:** confirmed, plus the tracking link.
- **Cancel:** cancelled, plus the clinic's phone.
- **Cancel inside the cutoff:** refused, plus the clinic's phone.

Changed from the unversioned template: no address line and no arrive-early
line. The headline is now the ask.

---

## 4. Turn Approaching Alert: `appointment_reminder_v2`

Sent by `clinic:send-reminders`, once per booking, on the visit day: when
`reminder_position` patients or fewer are still ahead (the tracking page's
count) and either the doctor has started or it is 15 minutes before the
booked time (`clinic.turn_alert.first_patients_lead_minutes`). Only
normal bookings not yet arrived. It replaced the pre-visit reminder and kept
its key. Template ID `1172295225303188`.

```
👋 أهلاً {{1}}،
دورك قرّب! ⏰

يُرجى التوجه للعيادة دلوقتي.

🕐 في حالة عدم التواجد وقت الدور، الدخول هيكون بعد الحالات المحجوزة الموجودة.
```

| | Value |
|---|---|
| `{{1}}` | Patient name |

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>`

> **Open:** the spec sends this when a set number of patients are left before
> the patient's turn. It's still timed from the scheduled slot, so it doesn't
> follow the live queue when emergencies push it back.

---

## 5. Emergency Delay: `appointment_delayed_v2`

Sent to each patient an emergency delay moves, with their own times. Never a
broadcast. Template ID `29134038209560264`.

```
👋 أهلاً {{1}}،
نعتذر، اتأجل موعدك بسبب حالة طارئة ⚠️

⏰ الموعد الجديد: {{2}} بدل {{3}}

📞 لو فيه أي استفسار، العيادة متاحة على: {{4}}

نشكرك على تفهمك 🙏
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | New time | 10:00 مساءً |
| `{{3}}` | Old time: the new time minus how far this booking moved | 09:00 مساءً |
| `{{4}}` | Clinic phone, E.164 | +201012345678 |

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>`

Each booking moves by its own amount: a gap before it absorbs part of the
delay. So `{{3}}` is that booking's own old time, not the first patient's.

---

## 6. Cancellation Apology: `booking_cancellation_v2`

Sent from the broadcast screen. **Sending it cancels every pending booking on
the day.** Template ID `1428443785558956`.

```
👋 أهلاً {{1}}،
نعتذر، اتلغى موعدك بسبب ظرف طارئ ⚠️

📅 الموعد الملغي: {{2}} الساعة {{3}}

اختيار ميعاد جديد متاح بسهولة من الزرار تحت 👇

📞 لأي استفسار، العيادة متاحة على: {{4}}

شكرًا لتفهمك 🙏
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Cancelled date | 15 أكتوبر 2026 |
| `{{3}}` | Cancelled time | 10:00 مساءً |
| `{{4}}` | Clinic phone, E.164 | +201244545454 |

**Button:** URL `احجز ميعاد جديد` → `<clinic slug>/book` (the clinic's
booking page)

Changed from the unversioned template:
- The doctor's name is gone and the time is added.
- A rebooking button replaces the promise that the clinic will call.

The cancellation is stored as `cancelled` (reason `emergency`), never
`no_show`, so it doesn't count toward the no-show block.

> **Open:**
> - The spec says the booking page opens with the patient's details filled
>   in. Today it only does that for a phone number already verified in that
>   browser.
> - Cancelling **one** booking still sends nothing. This template only goes
>   out for a whole-day cancel.

---

## 7. Feedback Request: `appointment_rating_v4`

Sent the moment a visit is marked done. Template ID `1143286641896246`.

```
👋 أهلاً {{1}}،
شكرًا لزيارتك النهارده 🙏

رأيك في تجربة الحجز والانتظار بيفرق معانا، والتقييم مش هياخد غير دقيقة.

ولحجز ميعاد جديد في أي وقت، الزرار متاح تحت 👇
```

| | Value |
|---|---|
| `{{1}}` | Patient name |

**Buttons:** two URL buttons, in this order:

| Index | Text | Path |
|---|---|---|
| 0 | `قيّم تجربتك` | `review/<tracking_token>` |
| 1 | `احجز ميعاد جديد` | `<clinic slug>/book` |

Each button gets its own button component, with its own `index`. The message
row keeps the first path in `button_suffix`, as for every other template, and
both paths in `button_suffixes`.

---

## 8. Waiting List: Joined: `waiting_list_joined`

Sent when a patient joins a full day's waiting list, or the secretary adds
them.

```
👋 أهلاً {{1}}،
*تم تسجيلك في قائمة الانتظار* ✅

📅 اليوم المطلوب: {{2}}

أول ما يفضى ميعاد، العيادة هتتواصل على الرقم ده لتأكيد الحجز.

⚠️ التسجيل في القائمة مش معناه إن الحجز اتأكد.
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | The day asked for | الثلاثاء 10 نوفمبر |

No button.

---

## 9. Waiting List: No Slot Opened: `waiting_list_no_slot_opened_v3`

Sent by `clinic:close-waiting-lists` once the day's last working period ends,
to patients still on its list.

```
👋 أهلاً {{1}}،

بخصوص تسجيلك في قائمة الانتظار ليوم {{2}}:

نعتذر، لم يتوفر موعد شاغر خلال اليوم المطلوب، ولذلك لم يتم تأكيد حجز.

يمكنك مراجعة المواعيد المتاحة من الزر بالأسفل.

شكرًا لتفهمك 🙏
```

| | Value |
|---|---|
| `{{1}}` | Patient name |
| `{{2}}` | The day asked for |

**Button:** URL → `<clinic slug>/book`

> **Not resubmitted yet.** The spec's wording is shorter and colloquial
> (`للأسف مفيش ميعاد فضي يوم {{2}} 😔` …, button `احجز ميعاد جديد`). It
> keeps the same two values, so a `_v4` needs only the name and text changed
> in the seeder.

---

## 10. Waiting List: Slot Booked: `waiting_list_slot_booked`

Sent **instead of** the confirmation when the secretary books a patient from
the waiting list, after calling them. No Visit Guidelines follow it.

```
👋 أهلاً {{1}}،
خبر حلو! اتحجزلك ميعاد من قائمة الانتظار 🎉

📅 التاريخ: {{2}}
⏰ الموعد: {{3}}
👨‍⚕️ الطبيب: {{4}}
📌 العنوان: {{5}}

⏳ الموعد ممكن يتأخر لو فيه حالة طارئة، وهنبلغك بأي تغيير.

🕐 في حالة التأخير عن الموعد، الدخول هيكون بعد الحالات المحجوزة الموجودة.
```

The values are the same as the confirmation's: patient, date, time, doctor,
address.

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>`

---

## 11. Clinic Announcement: `clinic_announcement_v2`

A super admin writes it on the clinic's page in the admin panel and picks the
patients. It's never offered in the clinic app.

```
📢 تنبيه من عيادة {{1}}

👋 أهلاً {{2}}،
{{3}}

لأي استفسار، العيادة متاحة على: {{4}}
شكرًا 🙏
```

| | Value |
|---|---|
| `{{1}}` | Doctor, with one `د.`: the same name as the `الطبيب` line elsewhere |
| `{{2}}` | Patient name |
| `{{3}}` | The announcement, one paragraph. Meta refuses line breaks inside a value, so any are folded into spaces. |
| `{{4}}` | Clinic phone, E.164 |

No button.

---

## 12. Clinic Report: `clinic_report`

The doctor's morning report. It goes to a clinic account, never to a patient,
and only for clinics with reports switched on. Submission details are in
[whatsapp-required-updates.md §7](whatsapp-required-updates.md).

| | Value | Sample |
|---|---|---|
| `{{1}}` | Period | أمس السبت 3 أكتوبر |
| `{{2}}` | Clinic | عيادة د. سهام عبدالعزيز |
| `{{3}}` | Completed visits | 4 |
| `{{4}}` | Income | 940 ج.م |

**Button:** URL `عرض التقرير` → `reports/day/2026-10-03` (or `week/…`,
`month/…`)

---

## Why every template is UTILITY

Proven on 2026-09-12. With the same recipient, sender and code path:

| Template | Category | Delivered |
|---|---|---|
| `appointment_booking_confirmation` ×3 | MARKETING | no |
| `appointment_rating` ×2 | MARKETING | no |
| `booking_cancellation` | **UTILITY** | **yes** |

Meta accepted all of them and returned a message ID, with no error. The
MARKETING ones never arrived. Meta caps marketing messages per person and
silently holds back the excess. A busy clinic sending several messages to the
same patients hits that cap, so confirmations disappear under exactly the
load the product is built for. Since `_v3`, everything has been submitted as
UTILITY.

Keep new templates operational: anything promotional gets classed MARKETING.

## Switching to a new version

Meta fixes a template's text, value count and buttons at approval, so a
changed template is submitted as a **new name** (`_v4`, …), never edited in
place. An edit that reorders values would put the patient's name where the
doctor's belongs, with no error.

1. Submit the new version as UTILITY, `ar`.
2. When approved, read it back from the Graph API.
3. In one deploy, change `provider_template_name` and `body_ar` in
   `MessageTemplateSeeder`, and the template's branch in
   `TemplatePayloadResolver` if its values changed. A count mismatch rejects
   every message.
4. After the deploy, run the migrations and the seeder on the server. The
   seeder isn't part of the deploy, so it won't run on its own. It only
   updates rows by key, so it's safe to re-run.

   ```
   docker compose -f compose.prod.yml exec app php artisan migrate --force
   docker compose -f compose.prod.yml exec app php artisan db:seed --class=MessageTemplateSeeder --force
   ```

   The November 2026 switch needs the migration too: it adds
   `outbound_messages.button_suffixes` for the rating's second button.

## Configuration

```
CLINIC_MESSAGING_DRIVER=cloud_api   # `log` until credentials are in place
WHATSAPP_SYSTEM_USER_TOKEN=...      # permanent system-user token; never commit
WHATSAPP_PHONE_NUMBER_ID=1287295631138307
WHATSAPP_API_VERSION=v25.0
```
