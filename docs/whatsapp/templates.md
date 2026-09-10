# WhatsApp message templates — ready to submit

Submit these five to Meta exactly as written. The code already expects these
names, so nothing needs changing once they are approved.

**Submit early.** Review typically takes days to weeks and nothing else in the
build waits on it — the app runs against a `log` driver until approval lands.

---

## Before submitting

| | |
|---|---|
| Language | **Arabic — `ar`** |
| Category | **UTILITY** for all five |
| Variables | `{{1}}` = patient name · `{{2}}` = clinic name · `{{3}}` = date and time · `{{4}}` = tracking link |

Only `booking_confirmed` uses `{{3}}` and `{{4}}`. The other three use the
first two and nothing else.

**Category matters.** UTILITY is for transactional notices about something the
customer already has — an appointment. It costs less per conversation and is
approved far more readily than MARKETING. If any of these is submitted as
MARKETING it will likely be rejected, and would cost more if it weren't.

**Why so few variables.** Every extra variable is another thing review can
object to. The three disruption notices need only name and clinic; the
confirmation needs the date and the link because that is the whole point of it.

---

## 0. `booking_confirmed`

Sent once, when the clinic takes a booking. This is the message that puts the
tracking link in the patient's hands — without it, the waiting counter has no
way of reaching them.

**Body**

```
مرحباً {{1}}، تم تأكيد حجزك في {{2}} يوم {{3}}. تقدر تتابع دورك من هنا: {{4}}
```

**Sample values for the submission form**

| | |
|---|---|
| `{{1}}` | سارة أحمد |
| `{{2}}` | عيادة د. سارة النجار |
| `{{3}}` | 2026-09-10 — 17:30 |
| `{{4}}` | https://elayadah.com/booking/gOeOEdLH3duyIjxDxvCZuByfaPoSuqTU |

**Justification** *(paste into the review notes)*

> Confirms an appointment the patient has just booked with the clinic, and
> gives her a private link to follow her position in the queue on the day.
> Sent once per booking, only to patients who booked and consented to WhatsApp
> updates. No promotional content.

### One decision to make before submitting

Meta accepts a URL inside the body as a variable, which is what the body above
does and what the code renders today. Meta *prefers* a **URL button with a
dynamic suffix**:

| | |
|---|---|
| Button type | Visit website — Dynamic |
| URL | `https://elayadah.com/booking/{{1}}` |
| Suffix sample | `gOeOEdLH3duyIjxDxvCZuByfaPoSuqTU` |

The button form gets approved more readily and renders as a proper tappable
button. If you submit it that way, drop `{{4}}` from the body and tell us — the
send path needs the token passed as a button parameter instead of inlined, and
that is a small change on our side.

---

## 0b. `visit_completed`

Sent once, the moment the secretary marks the visit **done**. Thanks the
patient and invites them to rate the visit.

**Approved by Meta — this section records what was approved so the code and
the template cannot drift apart. Fill in the two blanks below from the Meta
template manager.**

| | |
|---|---|
| Template name | `visit_completed` *(confirm the exact name)* |
| Language | **Arabic — `ar`** |
| Category | **UTILITY** |

**Body** *(as approved — replace with the exact wording if it differs)*

```
قيّم تجربتك

وقتك أغلى حاجة عندنا، ونفسنا نعرف وقّرناه فعلًا؟

تقدر تعمل تقييم سريع عن تجربتك في نظام الحجز الجديد

حجزك أسهل، ووقتك أثمن
```

**Button — this is the part that matters**

| | |
|---|---|
| Type | **Visit website — Dynamic** |
| Label | `المشاركة في التقييم` |
| Base URL | `https://elayadah.com/review/` |
| Suffix variable | `{{1}}` — the booking's tracking token |
| Suffix sample | `TA6pX6LqReDHCa5KUSJs5VGxUnHNiC2G` |

The full link a patient receives therefore looks like:

```
https://elayadah.com/review/TA6pX6LqReDHCa5KUSJs5VGxUnHNiC2G
```

**The suffix must be dynamic.** A static URL would open the review page with
no idea which visit, patient, doctor or clinic it belongs to, and every review
would arrive anonymous and unusable.

**Justification** *(paste into the review notes)*

> Thanks a patient for a visit that has just taken place and invites them to
> rate it. Sent once per completed appointment, only to patients who booked
> and consented to WhatsApp updates. No promotional content.

**What the link opens**

A page dedicated to the review — not the tracking page. It offers three
ratings and an optional note, accepts one submission per visit, and refuses
politely if the visit is not finished or has already been rated.

---

## 1. `day_cancelled`

Sent when the doctor cancels the day. **This one also cancels the bookings in
the system** and frees their slots.

**Body**

```
مرحباً {{1}}، نعتذر عن إلغاء مواعيد اليوم في {{2}} لظرف طارئ. سنتواصل معك لتحديد موعد جديد في أقرب وقت.
```

**Sample values for the submission form**

| | |
|---|---|
| `{{1}}` | سارة أحمد |
| `{{2}}` | عيادة د. سارة النجار |

**Justification** *(paste into the review notes)*

> Notifies a patient that her existing appointment for today has been cancelled
> due to an emergency at the clinic, and that the clinic will contact her to
> rebook. Sent only to patients who booked an appointment and consented to
> WhatsApp updates. No promotional content.

---

## 2. `appointment_earlier`

Sent when the clinic is running ahead and wants patients to come sooner.
**Notification only** — it does not move anything in the system.

**Body**

```
مرحباً {{1}}، نود إبلاغك بإمكانية تقديم موعد الكشف اليوم في {{2}}. برجاء الحضور في أقرب وقت يناسبك.
```

**Sample values**

| | |
|---|---|
| `{{1}}` | سارة أحمد |
| `{{2}}` | عيادة د. سارة النجار |

**Justification**

> Informs a patient with an appointment today that the clinic can see her
> earlier than booked. Sent only to patients with an existing appointment who
> consented to WhatsApp updates. No promotional content.

---

## 3. `appointment_delayed`

Sent when the clinic is running late. **Notification only.**

**Body**

```
مرحباً {{1}}، نعتذر عن التأخير في مواعيد الكشف اليوم في {{2}} لظرف طارئ. سيتم استقبالك في أقرب وقت ممكن، ونشكر لك تفهمك.
```

**Sample values**

| | |
|---|---|
| `{{1}}` | سارة أحمد |
| `{{2}}` | عيادة د. سارة النجار |

**Justification**

> Informs a patient with an appointment today that the clinic is running behind
> schedule. Sent only to patients with an existing appointment who consented to
> WhatsApp updates. No promotional content.

---

## Rules these were written against

Worth knowing, in case Meta asks for an edit:

- A template body **cannot start or end with a variable**. All three open with
  `مرحباً` and close with text.
- **No two variables may sit next to each other.** There is always wording
  between `{{1}}` and `{{2}}`.
- Variables must be numbered **sequentially from 1**, with no gaps.
- Body limit is 1024 characters. These are well under.

If review asks for a change, send me the wording they accepted — the rendered
text lives in the database, so a reworded template is a data change, not a
deploy.

---

## What else you need on your side

1. **Meta Business verification** for the company
2. **A WhatsApp Business Account**, and a **phone number** dedicated to it —
   that number can no longer be used in the normal WhatsApp app
3. **Provider choice** — Meta Cloud API directly (cheaper) or a BSP such as
   Twilio or 360dialog (faster to get live). Tell me which and I will point the
   driver at it.

---

## What the app does before approval

Everything except actually sending. The broadcast screen selects patients,
renders the chosen template, records each message and shows its delivery state
— all against a `log` driver that writes to the database instead of Meta.

When approval lands, the driver is swapped in configuration. **No application
code changes.**
