# WhatsApp templates in use

The two templates the system sends today. Submit or verify exactly as written —
the code matches these names and this variable order.

Everything else lives in [templates.md](templates.md).

| | |
|---|---|
| Language | Arabic — `ar` |
| Category | UTILITY |
| Domain | `https://elayadah.com` |

---

## 1. `booking_confirmed`

Sent the moment the clinic takes a booking.

**Body**

```
مرحباً {{1}}، تم تأكيد حجزك في {{2}} يوم {{3}}. تقدر تتابع دورك من هنا: {{4}}
```

| Variable | Meaning | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Clinic name | عيادة د. سارة النجار |
| `{{3}}` | Date and time | 2026-09-10 — 17:30 |
| `{{4}}` | Tracking link | `https://elayadah.com/booking/gOeOEdLH3duyIjxDxvCZuByfaPoSuqTU` |

**Review notes**

> Confirms an appointment the patient has just booked with the clinic, and gives
> her a private link to follow her position in the queue on the day. Sent once
> per booking, only to patients who consented to WhatsApp updates. No
> promotional content.

---

## 2. `visit_completed`

Sent the moment the clinic marks the visit finished.

**Body**

```
شكراً لزيارتك {{1}} في {{2}}. وقتك أغلى حاجة عندنا، ونفسنا نعرف رأيك في تجربتك. التقييم بياخد أقل من دقيقة.
```

| Variable | Meaning | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Clinic name | عيادة د. سارة النجار |

**Button — required**

| | |
|---|---|
| Type | Visit website — **Dynamic** |
| Label | المشاركة في التقييم |
| URL | `https://elayadah.com/review/{{1}}` |
| Suffix sample | `Vb4c6tHBlYLtZDonPapbQMNUwsQsqZi7` |

The suffix **must** be dynamic. A static URL makes every review anonymous.

**Review notes**

> Thanks a patient for a visit that has just taken place and invites them to
> rate it. Sent once per completed appointment, only to patients who consented
> to WhatsApp updates. No promotional content.

---

## Confirm back to us

1. The exact approved template names, if they differ from the two above.
2. That the `visit_completed` button URL is dynamic and matches `https://elayadah.com/review/{{1}}`.
