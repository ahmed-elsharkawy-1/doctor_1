# WhatsApp — required updates

> **Sending this to someone now?** Use
> [whatsapp-priority-updates.md](whatsapp-priority-updates.md) instead — the
> four items that are costing us something today, with no template rewrites.
> This file is the full picture, including the tone work parked for later.

Six items, all of them changes in the Meta dashboard. None can be done from the
codebase.

Once a template is approved we have a short piece of work to point the app at
it — the rewrites move the parameters around, so it is not only a settings
change. Plan for a day between approval and the new templates going live.

| # | Action | Consequence today |
|---|---|---|
| 1 | Re-categorise 2 templates to **UTILITY** | confirmations are being dropped |
| 2 | Create an **AUTHENTICATION** template | patient self-booking cannot launch |
| 3 | Submit 3 rewritten templates | cancelling one booking notifies nobody |
| 4 | Register the webhook, send the app secret | we cannot see delivery failures |
| 5 | Re-verify the phone number | verification has **expired** |
| 6 | Meta Business Verification | sender shows as a bare number |

**Items 1, 2 and 3 can go in a single review cycle.** Submit the new wording
already categorised UTILITY, with the verification template alongside.

---

## 1. Two templates are MARKETING and are being dropped

Meta caps how many marketing messages one person receives and **withholds the
excess silently** — the API returns success with a valid message ID, and the
message never arrives.

Measured 12 September; same recipient, same sender, same minute:

| Template | Category | Delivered |
|---|---|---|
| `appointment_booking_confirmation` ×3 | MARKETING | **no** |
| `appointment_rating` ×2 | MARKETING | **no** |
| `booking_cancellation` ×1 | **UTILITY** | **yes** |

All six were accepted. Only the UTILITY one arrived.

The cap trips on repeat messages to the same person in a short window — which
is a clinic's normal day. **The busier the clinic, the more confirmations
vanish.**

**The case for UTILITY:** both describe a transaction the patient has already
entered into. Neither promotes anything. `booking_cancellation` was approved as
UTILITY with comparable content — cite it as precedent.

> Please confirm the two categories in the dashboard before acting. The test
> above is the most recent evidence we have; if either was re-categorised since,
> this item is already done.

---

## 2. New: a verification-code template

Patients can now book themselves on the web. Before a booking is saved we send
a one-time code to the number the visit will be filed under, because patients
are matched on **phone alone** — an unverified number files a visit into the
wrong person's medical history.

**The feature is built and tested. It cannot go live without this template.**

| Field | Value |
|---|---|
| Category | **AUTHENTICATION** — not UTILITY, not MARKETING |
| Language | `ar` |
| Suggested name | `booking_verification_code` |
| Parameters | one: the code |
| Button | **Copy code** |
| Expiry warning | **10 minutes** — must match our setting |

**This template will not match the other three, and cannot.** Meta writes the
body of an authentication template itself and allows no custom wording — so no
doctor name heading, no signature, no slogan. Only the language, the button and
the add-ons are ours to choose. The shape in
[proposed_templates.md](proposed_templates.md) applies to the other three only.

Two notes: AUTHENTICATION is **not** subject to the cap in section 1, so codes
will not be silently dropped. And it is **billed per message** at its own rate —
one per booking attempt, capped on our side at 3 per number per hour.

---

## 3. Three rewritten templates

Full text and parameter tables: **[proposed_templates.md](proposed_templates.md)**

Submit as **new templates, not edits.** Two of them take the same parameters in
a different order, so an in-place edit would put the patient's name where the
doctor's belongs — with no error, just wrong messages. Suggested names:
`appointment_confirmed_v2`, `appointment_rating_v2`, `appointment_cancelled_v2`.

Why they need rewriting:

- **None of them says who sent it.** Patients see an unknown number with no
  stated source, which is what makes a confirmation feel like spam.
- **Cancelling a single booking notifies nobody today** — from the mobile app
  and the clinic web app alike. The live template says *we cancelled **today's
  appointments** due to an **emergency***, and both halves are false for one
  routine cancellation, so nothing is sent at all. The rewrite says *your
  appointment* and names **no reason**, which makes one template correct for a
  single booking and for a whole day.
- **The rating template is registered `en_US` while written entirely in
  Arabic.** Register the replacement as `ar`.

Two of the three carry a URL button. The label and suffix in the proposal must
be copied exactly, or the tracking and review links will not resolve.

---

## 4. Delivery receipts

Meta calls a URL on our server whenever a message is delivered, read or fails.
That is the only way we learn whether a message arrived. Without it **we cannot
tell a delivered message from a dropped one** — establishing that section 1 was
happening at all took most of a day for this reason.

The endpoint is built, deployed and live. It refuses every callback on purpose
until the app secret is set, because an unverifiable receipt is worth less than
none.

### Step 1 — register the callback URL

Meta cannot discover the URL; it has to be entered. **Saving it is what turns
the feature on** — there is no separate switch.

**Meta → WhatsApp → Configuration → Webhook**

| Field | Value |
|---|---|
| Callback URL | `https://elayadah.com/webhooks/whatsapp` |
| Verify token | `da9314a7302b9e886336570420a7956126976c446f853edb` |
| Subscribe to | the **`messages`** field |

The verify token above is already set on our server — paste it exactly as
written. Meta tests it once, the moment the URL is saved.

### Step 2 — send us one value

**Meta → Settings → Basic → App Secret**

Meta signs every callback with it, and we check that signature to know the
request genuinely came from Meta. Without it a stranger could mark any message
delivered, or failed.

**This is the only value we are missing.** It is not the system user token used
to *send* messages — that one is already configured and working.

---

## 5. The phone number's verification has expired

Meta currently reports `code_verification_status: EXPIRED` for the business
number. It should read `VERIFIED`.

Re-verify the number in **Meta → WhatsApp → Phone Numbers**. This is also very
likely to block section 6, since Meta will not approve a display name for a
number whose verification has lapsed.

---

## 6. The sender shows as a number, not a name

Patients see `+20 12 83176126`, not the clinic's name. For an unsolicited
message about a medical appointment, an unnamed number is the biggest reason to
distrust it — and to report it, which damages the quality rating for every
clinic on the platform.

What Meta holds for the number today:

```
verified_name                 العيادة - Elayadah   ← the name is set
name_status                   AVAILABLE_WITHOUT_REVIEW   ← not APPROVED
code_verification_status      EXPIRED              ← see section 5
is_official_business_account  false
quality_rating                GREEN
```

The name exists but is not in the approved state that makes it display. The
step that changes this is **Meta Business Verification** — submitting the
company's legal documents in Business Manager. Settle section 5 first.
