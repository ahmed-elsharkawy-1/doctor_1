# WhatsApp templates for multi-branch (ELA-22)

**Status: drafts, not submitted to Meta.**

This document describes the next version of each patient template once a
doctor can have several branches. The templates that are live today are in
[`current_templates.md`](current_templates.md). That file stays the record of
what is sent until these versions are approved and switched on.

## The rules from ELA-22

- Every message about a booking shows the booked branch on its own line:
  `🏥 العيادة: <branch name>`.
- The line is always there, including for doctors with one branch. There is
  one version per template, with no single-branch variant.
- The address is the booked branch's address. The phone is the branch's
  phone, or the clinic's phone (`clinics.phone`) if the branch has none.
- "احجز ميعاد جديد" links open the booking page with a branch already chosen.
  It is the same branch for Cancellation Apology and No Slot Opened, and the
  patient's last-visited branch for Feedback Request. The URL buttons are
  registered as `https://elayadah.com/{{1}}`, so this is a change to the path
  we send, not to the template.
- The branch value is the branch name exactly as the doctor typed it, for
  example `عيادة جديلة`.

All drafts stay **UTILITY** and **`ar`**. Each is submitted under a new name.
Values are numbered in the order they appear in the text.

## Summary

| # | Our key | Today (live) | New Meta name | Values | Change |
|---|---|---|---|---|---|
| 1 | `booking_confirmed` | `appointment_booking_confirmation_v4` | `appointment_booking_confirmation_v5` | 5 → 6 | branch line, branch address |
| 2 | `appointment_reminder_today` | `appointment_reminder_today_v2` | `appointment_reminder_today_v3` | 4 → 5 | branch line |
| 3 | `appointment_reminder` | `appointment_reminder_v2` | `appointment_reminder_v3` | 1 → 2 | branch line |
| 4 | `appointment_delayed` | `appointment_delayed_v2` | `appointment_delayed_v3` | 4 → 5 | branch line, branch phone |
| 5 | `day_cancelled` | `booking_cancellation_v2` | `booking_cancellation_v3` | 4 → 5 | branch line, branch phone, link to the branch |
| 6 | `waiting_list_joined` | `waiting_list_joined` | `waiting_list_joined_v2` | 2 → 3 | branch line |
| 7 | `waiting_list_no_slot_opened` | `waiting_list_no_slot_opened_v3` | `waiting_list_no_slot_opened_v4` | 2 → 3 | branch line, link to the branch |
| 8 | `waiting_list_slot_booked` | `waiting_list_slot_booked` | `waiting_list_slot_booked_v2` | 5 → 6 | branch line, branch address |

Not resubmitted, because only the values or links change:

| Our key | Meta name | Change |
|---|---|---|
| `visit_completed` | `appointment_rating_v4` | The second button opens `<slug>/book?b=<last-visited branch>` |
| `clinic_announcement` | `clinic_announcement_v2` | `{{1}}` is `د. … – <branch>` for one branch and the doctor alone for "All branches". `{{4}}` is that branch's phone. |
| `visit_guidelines` | `visit_guidelines` | None. Its tracking link already opens the booking's branch. |
| `clinic_report` | `clinic_report` | None. It goes to the doctor. |
| reminder follow-up replies | free text, `lang/ar/messages.php` | The phone becomes the branch phone. |

---

## 1. Booking Confirmation: `appointment_booking_confirmation_v5`

```
👋 أهلاً {{1}}،
تم تأكيد حجزك بنجاح ✅

📅 التاريخ: {{2}}
⏰ الموعد: {{3}}
👨‍⚕️ الطبيب: {{4}}
🏥 العيادة: {{5}}
📌 العنوان: {{6}}

⏳ الموعد ممكن يتأخر لو فيه حالة طارئة، وهنبلغك بأي تغيير.

📋 تعليمات الزيارة هتوصلك في الرسالة الجاية.
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Date | 15 سبتمبر 2026 |
| `{{3}}` | Time | 06:30 مساءً |
| `{{4}}` | Doctor, with one `د.` | د. نرمين ناصر |
| `{{5}}` | Branch name | عيادة جديلة |
| `{{6}}` | Branch address | 12 شارع الجمهورية، جديلة |

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>` (unchanged)

## 2. Reminder + Confirm / Cancel: `appointment_reminder_today_v3`

```
👋 أهلاً {{1}}،
يُرجى تأكيد حضورك لموعدك ⏰

📅 التاريخ: {{2}}
⏰ الموعد: {{3}}
👨‍⚕️ الطبيب: {{4}}
🏥 العيادة: {{5}}

ولو الظروف اتغيرت، الإلغاء بدري بيدي الفرصة لمريض تاني محتاج الميعاد.
```

| | Value |
|---|---|
| `{{1}}` | Patient name |
| `{{2}}` | Date |
| `{{3}}` | Time |
| `{{4}}` | Doctor, with one `د.` |
| `{{5}}` | Branch name |

**Buttons:** quick replies `تأكيد الحضور` · `إلغاء الحجز` (unchanged). The
replies after a tap give the branch phone.

## 3. Turn Approaching Alert: `appointment_reminder_v3`

```
👋 أهلاً {{1}}،
دورك قرّب! ⏰
🏥 العيادة: {{2}}

يُرجى التوجه للعيادة دلوقتي.

🕐 في حالة عدم التواجد وقت الدور، الدخول هيكون بعد الحالات المحجوزة الموجودة.
```

| | Value |
|---|---|
| `{{1}}` | Patient name |
| `{{2}}` | Branch name |

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>` (unchanged)

## 4. Emergency Delay: `appointment_delayed_v3`

```
👋 أهلاً {{1}}،
نعتذر، اتأجل موعدك بسبب حالة طارئة ⚠️
🏥 العيادة: {{2}}

⏰ الموعد الجديد: {{3}} بدل {{4}}

📞 لو فيه أي استفسار، العيادة متاحة على: {{5}}

نشكرك على تفهمك 🙏
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Branch name | عيادة جديلة |
| `{{3}}` | New time | 10:00 مساءً |
| `{{4}}` | Old time | 09:00 مساءً |
| `{{5}}` | Branch phone, else clinic phone, E.164 | +201012345678 |

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>` (unchanged)

## 5. Cancellation Apology: `booking_cancellation_v3`

```
👋 أهلاً {{1}}،
نعتذر، اتلغى موعدك بسبب ظرف طارئ ⚠️

📅 الموعد الملغي: {{2}} الساعة {{3}}
🏥 العيادة: {{4}}

اختيار ميعاد جديد متاح بسهولة من الزرار تحت 👇

📞 لأي استفسار، العيادة متاحة على: {{5}}

شكرًا لتفهمك 🙏
```

| | Value | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Cancelled date | 15 أكتوبر 2026 |
| `{{3}}` | Cancelled time | 10:00 مساءً |
| `{{4}}` | Branch name | عيادة جديلة |
| `{{5}}` | Branch phone, else clinic phone, E.164 | +201244545454 |

**Button:** URL `احجز ميعاد جديد` → `<clinic slug>/book?b=<branch slug>`

## 6. Waiting List: Joined: `waiting_list_joined_v2`

```
👋 أهلاً {{1}}،
*تم تسجيلك في قائمة الانتظار* ✅

📅 اليوم المطلوب: {{2}}
🏥 العيادة: {{3}}

أول ما يفضى ميعاد، العيادة هتتواصل على الرقم ده لتأكيد الحجز.

⚠️ التسجيل في القائمة مش معناه إن الحجز اتأكد.
```

| | Value |
|---|---|
| `{{1}}` | Patient name |
| `{{2}}` | The day asked for |
| `{{3}}` | Branch name |

No button.

## 7. Waiting List: No Slot Opened: `waiting_list_no_slot_opened_v4`

```
👋 أهلاً {{1}}،

بخصوص تسجيلك في قائمة الانتظار ليوم {{2}}:
🏥 العيادة: {{3}}

نعتذر، لم يتوفر موعد شاغر خلال اليوم المطلوب، ولذلك لم يتم تأكيد حجز.

يمكنك مراجعة المواعيد المتاحة من الزر بالأسفل.

شكرًا لتفهمك 🙏
```

| | Value |
|---|---|
| `{{1}}` | Patient name |
| `{{2}}` | The day asked for |
| `{{3}}` | Branch name |

**Button:** URL → `<clinic slug>/book?b=<branch slug>`

> The spec's shorter, colloquial wording for this message, noted in
> `current_templates.md`, was never submitted. If it is wanted, this is the
> moment: it goes in the same `_v4`.

## 8. Waiting List: Slot Booked: `waiting_list_slot_booked_v2`

```
👋 أهلاً {{1}}،
خبر حلو! اتحجزلك ميعاد من قائمة الانتظار 🎉

📅 التاريخ: {{2}}
⏰ الموعد: {{3}}
👨‍⚕️ الطبيب: {{4}}
🏥 العيادة: {{5}}
📌 العنوان: {{6}}

⏳ الموعد ممكن يتأخر لو فيه حالة طارئة، وهنبلغك بأي تغيير.

🕐 في حالة التأخير عن الموعد، الدخول هيكون بعد الحالات المحجوزة الموجودة.
```

The values match the confirmation: patient, date, time, doctor, branch name,
branch address.

**Button:** URL `تابع دورك وتفاصيل حجزك` → `booking/<tracking_token>` (unchanged)

---

## Rollout

1. Submit all 8 as UTILITY, `ar`, under the new names above.
2. The code is already in place (ELA-22, Phase 7a). The new names live in
   `config/clinic.php` → `messaging.branch_template_names`, behind one
   switch, `CLINIC_WHATSAPP_BRANCH_TEMPLATES` (default `false`). While it
   is off, the app sends today's templates with today's values. It already
   uses the booked branch's address and phone (the branch's, or the clinic's
   main number), and its "book again" links already open on the same
   branch. Those are values, not template changes, so they need no approval.
3. When **all 8** are approved, read each back from the Graph API (name,
   value count, button order, button URL). Then set
   `CLINIC_WHATSAPP_BRANCH_TEMPLATES=true` and restart the workers. No
   seeder run is needed: the switch picks the names.
4. Move these texts into `current_templates.md` and mark the old names
   retired.

The switch is all-or-nothing on purpose. If some templates went live with the
branch line and others without it, the same booking would show its branch in
one message and not in the next.
