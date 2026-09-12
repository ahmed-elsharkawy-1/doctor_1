# Proposed templates — one voice

The three live templates were written separately and read that way: one opens
with the patient's name, one opens with nothing at all, and the third is in
heavy Egyptian colloquial. None of them names the clinic, so a patient's first
sight of the message is an unknown number and no source.

These three replace them with one shape.

## The shape

```
*<clinic>*
<doctor>

مرحبًا <patient>، <what this message is about>

<the details, if any>

<closing line>
حجزك أسهل، ووقتك أثمن
```

Why in this order: the clinic and doctor come first because that is the
question a patient asks on seeing an unfamiliar number — *who is this?* Their
own name comes next, because a message addressed to nobody reads as a blast.
Only then the content.

Everything is plain modern standard Arabic with a warm register. No dialect: a
clinic writing to a patient it may not have met should not sound like a
WhatsApp group.

---

## 1. Booking confirmed

Sent automatically the moment a booking is taken.

```
*{{1}}*
{{2}}

مرحبًا {{3}}، تم تأكيد حجزك ✅

🗓 التاريخ: {{4}}
🕐 الموعد: {{5}}
📍 العنوان: {{6}}
⏰ يُرجى الحضور قبل الموعد بـ {{7}}

يمكنك متابعة حجزك ومعرفة دورك من الرابط بالأسفل.

نتمنى لك دوام الصحة
حجزك أسهل، ووقتك أثمن
```

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Clinic | عيادة د. سهام عبدالعزيز |
| `{{2}}` | Doctor | د. سهام عبدالعزيز |
| `{{3}}` | Patient | أحمد محمد |
| `{{4}}` | Date | 12 سبتمبر 2026 |
| `{{5}}` | Time | 01:10 مساءً |
| `{{6}}` | Address | برج الأتربي، شارع قناة السويس، المنصورة |
| `{{7}}` | Arrival lead | 30 دقيقة |

**Button** — `متابعة الحجز` → `https://elayadah.com/{{1}}`, suffix `booking/<token>`

The doctor moves out of the details list and into the header, so the name is
not printed twice.

---

## 2. Rating request

Sent automatically when the visit is marked finished.

```
*{{1}}*
{{2}}

مرحبًا {{3}}، شكرًا لزيارتك اليوم 🌷

نتمنى أن تكون تجربتك كانت مريحة. رأيك يساعدنا على تحسين الخدمة،
والتقييم لا يستغرق أكثر من دقيقة.

حجزك أسهل، ووقتك أثمن
```

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Clinic | عيادة د. سهام عبدالعزيز |
| `{{2}}` | Doctor | د. سهام عبدالعزيز |
| `{{3}}` | Patient | أحمد محمد |

**Button** — `المشاركة في التقييم` → `https://elayadah.com/{{1}}`, suffix `review/<token>`

The live version has **no parameters at all** — no name, no clinic, no doctor —
and opens on *وقتك أغلى حاجة عندنا*. It is the least personal of the three and
the one most likely to be read as spam. It also refers to *نظام الحجز الجديد*,
which is us; the patient's visit was to their doctor.

---

## 3. Booking cancelled

Sent when a booking is cancelled. **Deliberately worded for one booking or a
whole day**, so the same template serves both.

```
*{{1}}*
{{2}}

مرحبًا {{3}}، نعتذر عن إلغاء موعدك 🙏

كان موعدك يوم {{4}}، واضطررنا إلى إلغائه لظرف طارئ.

سنتواصل معك قريبًا لتحديد موعد جديد، ولأي استفسار يمكنك
التواصل معنا على {{5}}.

شكرًا لتفهمك
حجزك أسهل، ووقتك أثمن
```

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Clinic | عيادة د. سهام عبدالعزيز |
| `{{2}}` | Doctor | د. سهام عبدالعزيز |
| `{{3}}` | Patient | أحمد محمد |
| `{{4}}` | Date | 12 سبتمبر 2026 |
| `{{5}}` | Clinic phone | +20 101 745 5239 |

No button.

The live version says *اضطرينا نلغي **مواعيد اليوم***, which is only true for a
whole-day cancellation. That is why a single cancellation currently sends
nothing at all — there is no honest template for it. This wording removes that
block, and the cancellation notice can then be built.

---

## Submit these as new templates, not edits

Editing a live template replaces it on approval. Our code sends an exact
parameter count, so between Meta approving the new text and our deploy landing,
**every send fails** with `#132000 Number of parameters does not match`.

Creating new templates avoids that window entirely:

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
being silently dropped. All three are transactional — they describe an
appointment the patient has already made.

`booking_cancellation` was approved as UTILITY with comparable content, which
is the precedent to cite.

## And register the rating template as `ar`

The live one is registered `en_US` while being written entirely in Arabic. It
works, but only because the code sends `en_US` to match. New templates should
declare the language they are actually in.
