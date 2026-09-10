# WhatsApp templates in use

The three templates the system sends today, exactly as they are registered at
Meta. Read back from the Graph API, not transcribed from a screenshot — the
parameter lists below are the contract, and a mismatch is rejected whole
(`#132000 Number of parameters does not match`).

Everything else lives in [templates.md](templates.md).

| | |
|---|---|
| Business | Elayadah |
| Sender | +20 12 83176126 (VERIFIED, quality GREEN) |
| Phone number ID | `1287295631138307` |
| Graph version | `v25.0` |
| Domain | `https://elayadah.com` |

Every URL button is registered as `https://elayadah.com/{{1}}`, so the button
parameter is a **whole path**, not just a token: `booking/<token>`.

---

## 1. `appointment_booking_confirmation`

Sent automatically the moment a booking is taken, on the mobile API and the web
alike. Our key: `booking_confirmed`.

| | |
|---|---|
| Language | `ar` |
| Category | MARKETING — see the note at the bottom |
| Template ID | `28065670616437821` |

**Body — 6 parameters, in this order**

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Date | 13 سبتمبر 2026 |
| `{{3}}` | Time | 09:00 صباحًا |
| `{{4}}` | Doctor | د. سارة النجار |
| `{{5}}` | Clinic address | 12 شارع مصدق، الدقي، الجيزة |
| `{{6}}` | Arrival lead | 15 دقيقة |

**Button** — `متابعة الحجز` → suffix `booking/<tracking_token>`

An emergency booking has no time slot, so `{{3}}` says so rather than going
out empty, which Meta rejects.

---

## 2. `appointment_rating`

Sent automatically when the visit is marked done. Our key: `visit_completed`.

| | |
|---|---|
| Language | **`en_US`** — the text is Arabic, but the template is registered under `en_US`. Sending `ar` fails. |
| Category | MARKETING — see the note at the bottom |
| Template ID | `2882522648752095` |

**Body — no parameters at all.** The text is fixed; only the button varies.

**Button** — `المشاركة في التقييم` → suffix `review/<tracking_token>`

---

## 3. `booking_cancellation`

Sent from the broadcast screen when the clinic cancels a day. Our key:
`day_cancelled`. **Sending it cancels every pending booking on the day.**

| | |
|---|---|
| Language | `ar` |
| Category | UTILITY |
| Template ID | `1403452804675711` |

**Body — 4 parameters, in this order**

| | Meaning | Sample |
|---|---|---|
| `{{1}}` | Patient name | سارة أحمد |
| `{{2}}` | Doctor | د. سارة النجار |
| `{{3}}` | Date | 13 سبتمبر 2026 |
| `{{4}}` | Clinic phone | +201012223344 |

No button.

---

## Not submitted

`appointment_earlier` and `appointment_delayed` were drafted for the app but
never submitted to Meta. They are seeded **inactive**, which keeps them off the
broadcast screen — offering a send that is certain to fail is worse than not
offering it. Their parameter mapping exists in `TemplatePayloadResolver` and
must be re-checked against the approved template if they are ever submitted,
since Meta fixes the parameter list at approval.

## Open: the MARKETING category

Templates 1 and 2 are categorised MARKETING rather than UTILITY. Marketing
messages are frequency-capped by Meta, require marketing opt-in, cost more, and
**can be withheld** from a user who has limited marketing messages. An
appointment confirmation that silently does not arrive is worse than useless.

`booking_cancellation` is UTILITY and was approved as such, so the same content
would very likely pass as UTILITY. Worth re-submitting those two.

## Configuration

```
CLINIC_MESSAGING_DRIVER=cloud_api   # `log` until credentials are in place
WHATSAPP_SYSTEM_USER_TOKEN=...      # permanent system-user token; never commit
WHATSAPP_PHONE_NUMBER_ID=1287295631138307
WHATSAPP_API_VERSION=v25.0
```
