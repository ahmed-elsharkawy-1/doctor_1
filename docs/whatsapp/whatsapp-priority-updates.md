# WhatsApp — priority updates

Four items. Two are costing us something today; two make the first two
verifiable. All are changes in the Meta dashboard.

Tone and wording improvements are deliberately **not** here — they are parked
in [whatsapp-required-updates.md](whatsapp-required-updates.md) for later.

| # | Action | Why now |
|---|---|---|
| 1 | Change 2 templates to **UTILITY** | booking confirmations are being dropped |
| 2 | Create an **AUTHENTICATION** template | patient self-booking cannot launch |
| 3 | Register the webhook + send the app secret | otherwise we cannot tell whether 1 worked |
| 4 | Re-verify the phone number | verification has expired |

Nothing here requires rewriting or resubmitting an existing template.

---

## 1. Two templates are MARKETING and are being dropped

Change the category of these two to **UTILITY**:

- `appointment_booking_confirmation`
- `appointment_rating`

This is a category change on the existing templates — no new text, no
resubmission.

**Why it matters:** Meta caps how many marketing messages one person receives
and **withholds the excess silently**. The API returns success with a valid
message ID and the message never arrives. The cap trips on repeat messages to
the same person in a short window, which is a clinic's normal day — so the
busier the clinic, the more confirmations vanish.

Measured 12 September; same recipient, same sender, same minute:

| Template | Category | Delivered |
|---|---|---|
| `appointment_booking_confirmation` ×3 | MARKETING | **no** |
| `appointment_rating` ×2 | MARKETING | **no** |
| `booking_cancellation` ×1 | **UTILITY** | **yes** |

**The case for UTILITY:** both describe a transaction the patient has already
entered into. Neither promotes anything. `booking_cancellation` was approved as
UTILITY with comparable content — cite it as precedent.

> Please confirm both categories in the dashboard first. If either was changed
> since 12 September, that half is already done.

---

## 2. New: a verification-code template

Patients can now book themselves on the web. Before a booking is saved we send
a one-time code to the number the visit will be filed under, because patients
are matched on **phone alone** — an unverified number files a visit into the
wrong person's medical history.

**The feature is built, tested and deployed. It cannot be switched on without
this template.** This is the only item here blocking a finished feature.

| Field | Value |
|---|---|
| Category | **AUTHENTICATION** — not UTILITY, not MARKETING |
| Language | `ar` |
| Suggested name | `booking_verification_code` |
| Parameters | one: the code |
| Button | **Copy code** |
| Expiry warning | **10 minutes** |

**It will not look like our other templates, and cannot.** Meta writes the body
of an authentication template itself and allows no custom wording — no doctor
name, no signature, no slogan. Only the language, the button and the add-ons
are ours to choose.

Two notes: AUTHENTICATION is **not** subject to the cap in item 1, so codes
will not be dropped. And it is **billed per message** at its own rate — one per
booking attempt, capped on our side at 3 per number per hour.

---

## 3. Delivery receipts

Meta calls a URL on our server whenever a message is delivered, read or fails.

**This is how we will confirm item 1 actually worked.** Without it we cannot
tell a delivered message from a dropped one — which is why the dropped
confirmations went unnoticed for weeks in the first place. Doing item 1 without
this means changing the category and hoping.

The endpoint is built, deployed and live. It refuses every callback until the
app secret is set, because an unverifiable receipt is worth less than none.

### Step 1 — register the callback URL

Meta cannot discover the URL; it has to be entered. **Saving it is what turns
the feature on** — there is no separate switch.

**Meta → WhatsApp → Configuration → Webhook**

| Field | Value |
|---|---|
| Callback URL | `https://elayadah.com/webhooks/whatsapp` |
| Verify token | `da9314a7302b9e886336570420a7956126976c446f853edb` |
| Subscribe to | the **`messages`** field |

The verify token is already set on our server — paste it exactly as written.
Meta tests it once, the moment the URL is saved.

### Step 2 — send us one value

**Meta → Settings → Basic → App Secret**

Meta signs every callback with it, and we check that signature to know the
request genuinely came from Meta. Without it a stranger could mark any message
delivered, or failed.

**This is the only value we need.** It is not the token used to *send*
messages — that one is already configured and working.

---

## 4. The phone number's verification has expired

Meta currently reports `code_verification_status: EXPIRED` for the business
number. It should read `VERIFIED`.

Re-verify it in **Meta → WhatsApp → Phone Numbers**. Quick, and worth ruling
out as a second cause of delivery problems while item 1 is being investigated.

---

## Order

Items **1 and 2 can go together.** Items **3 and 4 are independent** and can
start immediately — neither waits on a template review.

Doing **3 before 1** is ideal: with delivery receipts live, the effect of the
category change is visible instead of assumed.
