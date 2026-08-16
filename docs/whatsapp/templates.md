# WhatsApp message templates — ready to submit

Submit these three to Meta exactly as written. The code already expects these
names, so nothing needs changing once they are approved.

**Submit early.** Review typically takes days to weeks and nothing else in the
build waits on it — the app runs against a `log` driver until approval lands.

---

## Before submitting

| | |
|---|---|
| Language | **Arabic — `ar`** |
| Category | **UTILITY** for all three |
| Variables | `{{1}}` = patient name · `{{2}}` = clinic name |

**Category matters.** UTILITY is for transactional notices about something the
customer already has — an appointment. It costs less per conversation and is
approved far more readily than MARKETING. If any of these is submitted as
MARKETING it will likely be rejected, and would cost more if it weren't.

**Why only two variables.** Every extra variable is another thing review can
object to. Name and clinic are enough to make the message feel addressed
without risking a rejection over a date format.

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
