# Proposed templates — one voice

The three live templates were written separately and read that way: one opens
with the patient's name, one opens with nothing at all, and the third is in
heavy Egyptian colloquial. None says who sent it, so a patient's first sight of
the message is an unknown number and no source.

These three replace them with one shape.

## The shape

```
*<doctor>*

مرحبًا <patient>، <what this message is about>

<the details, if any>

<closing line>
منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
```

**The doctor comes first** because that is the question a patient asks on
seeing an unfamiliar number — *who is this about?* Every clinic's name is
already the doctor's name with `عيادة` in front of it, so a separate clinic
line would only say the same thing twice.

**The patient's name comes next**, because a message addressed to nobody reads
as a blast.

**The signature says who sent it.** The sender shows patients a bare phone
number, so the message itself has to name the service. `منصة العيادة` rather
than `العيادة` alone, because in plain text with no logo beside it the single
word reads as *the clinic* — the doctor's clinic signing off — not as a brand.
The domain is the one every button opens, so each vouches for the other.

The signature and slogan are fixed text, not parameters: they cost nothing at
approval and cannot drift between clinics.

Everything is plain modern standard Arabic with a warm register. No dialect.

---

## 1. Booking confirmed

Sent automatically the moment a booking is taken.

```
*{{1}}*

مرحبًا {{2}}، تم تأكيد حجزك ✅

🗓 التاريخ: {{3}}
🕐 الموعد: {{4}}
📍 العنوان: {{5}}
⏰ يُرجى الحضور قبل الموعد بـ {{6}}

يمكنك متابعة حجزك ومعرفة دورك من الرابط بالأسفل.

نتمنى لك دوام الصحة
منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
```

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Doctor | د. سهام عبدالعزيز |
| `{{2}}` | Patient | أحمد محمد |
| `{{3}}` | Date | 12 سبتمبر 2026 |
| `{{4}}` | Time | 01:10 مساءً |
| `{{5}}` | Address | برج الأتربي، شارع قناة السويس، المنصورة |
| `{{6}}` | Arrival lead | 30 دقيقة |

**Button** — `متابعة الحجز` → `https://elayadah.com/{{1}}`, suffix `booking/<token>`

**How the patient sees it**

```
*د. سهام عبدالعزيز*

مرحبًا أحمد محمد، تم تأكيد حجزك ✅

🗓 التاريخ: 12 سبتمبر 2026
🕐 الموعد: 05:00 مساءً
📍 العنوان: برج الأتربي، شارع قناة السويس، المنصورة
⏰ يُرجى الحضور قبل الموعد بـ 30 دقيقة

يمكنك متابعة حجزك ومعرفة دورك من الرابط بالأسفل.

نتمنى لك دوام الصحة
منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
─────────────────────────
        🔗 متابعة الحجز
```

---

## 2. Rating request

Sent automatically when the visit is marked finished.

```
*{{1}}*

مرحبًا {{2}}، شكرًا لزيارتك اليوم 🌷

نتمنى أن تكون تجربتك كانت مريحة. رأيك يساعدنا على تحسين الخدمة،
والتقييم لا يستغرق أكثر من دقيقة.

منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
```

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Doctor | د. سهام عبدالعزيز |
| `{{2}}` | Patient | أحمد محمد |

**Button** — `المشاركة في التقييم` → `https://elayadah.com/{{1}}`, suffix `review/<token>`

**How the patient sees it**

```
*د. سهام عبدالعزيز*

مرحبًا أحمد محمد، شكرًا لزيارتك اليوم 🌷

نتمنى أن تكون تجربتك كانت مريحة. رأيك يساعدنا على تحسين الخدمة،
والتقييم لا يستغرق أكثر من دقيقة.

منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
─────────────────────────
     🔗 المشاركة في التقييم
```

The live version has **no parameters at all** — no name, no doctor — and opens
on *وقتك أغلى حاجة عندنا*. It is the least personal of the three and the one
most likely to be read as spam. It also thanks the patient for using *نظام
الحجز الجديد*, which is us; their visit was to their doctor.

---

## 3. Booking cancelled

Sent when a booking is cancelled. **Worded for one booking or a whole day**, so
the same template serves both.

```
*{{1}}*

مرحبًا {{2}}، نعتذر عن إلغاء موعدك 🙏

كان موعدك يوم {{3}}، واضطررنا إلى إلغائه لظرف طارئ.

سنتواصل معك قريبًا لتحديد موعد جديد، ولأي استفسار يمكنك
التواصل معنا على {{4}}.

شكرًا لتفهمك
منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
```

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Doctor | د. سهام عبدالعزيز |
| `{{2}}` | Patient | أحمد محمد |
| `{{3}}` | Date | 12 سبتمبر 2026 |
| `{{4}}` | Clinic phone | +20 101 745 5239 |

No button.

**How the patient sees it**

```
*د. سهام عبدالعزيز*

مرحبًا أحمد محمد، نعتذر عن إلغاء موعدك 🙏

كان موعدك يوم 12 سبتمبر 2026، واضطررنا إلى إلغائه لظرف طارئ.

سنتواصل معك قريبًا لتحديد موعد جديد، ولأي استفسار يمكنك
التواصل معنا على 01017455239.

شكرًا لتفهمك
منصة العيادة · elayadah.com
حجزك أسهل، ووقتك أثمن
```

The live version says *اضطرينا نلغي **مواعيد اليوم***, true only for a
whole-day cancellation. That is why a single cancellation sends nothing today:
there is no honest template for it. This wording removes that block.

---

## Still three templates, not four

The cancellation covers **both** a single booking and a whole day. Sent to one
patient it is true; sent to forty during a day cancellation each still reads
correctly about their own appointment.

## Submit these as new templates, not edits

This matters more now than it first did. Two of the rewrites take **the same
number of parameters as the live template they replace, in a different
order**:

| | Live | Rewrite |
|---|---|---|
| Confirmation | 6 — patient, date, time, doctor, address, lead | 6 — **doctor, patient**, date, time, address, lead |
| Rating | 0 | 2 — doctor, patient |
| Cancellation | 4 — patient, doctor, date, phone | 4 — **doctor, patient**, date, phone |

Edit a live template in place and, on approval, Meta would accept our old
parameters without complaint — the counts match — and send **the patient's
name where the doctor's belongs**. No error, no failed status, just wrong
messages to real patients until someone notices.

New names remove the window entirely:

| | |
|---|---|
| Old templates keep working | until we point at the new ones |
| Switching over | one field per row in `message_templates` |
| Rolling back | point the field back |
| Mismatch window | none |

Suggested names: `appointment_confirmed_v2`, `appointment_rating_v2`,
`appointment_cancelled_v2`.

## Submit all three as UTILITY

Not MARKETING. This has been [demonstrated, not
assumed](current_templates.md): marketing messages to the same patient are
being silently dropped. All three describe an appointment the patient has
already made.

`booking_cancellation` was approved as UTILITY with comparable content, which
is the precedent to cite.

## Register the rating template as `ar`

The live one is registered `en_US` while written entirely in Arabic. It works
only because our code sends `en_US` to match.

## The one assumption this shape makes

That a clinic is identified by its doctor. True of every clinic today — each
has one active doctor and is named after them. A future clinic with its own
brand name, or several doctors, would show only the doctor on duty. If that is
on the roadmap, it is worth deciding before submission, since the header cannot
change without another review.
