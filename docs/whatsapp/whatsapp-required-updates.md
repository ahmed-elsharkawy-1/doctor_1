# WhatsApp — everything waiting on the product team

One page, three areas. Nothing here can be done from the codebase: each item
needs someone with access to the Meta dashboard.

Ordered by what is currently costing us most.

---

# 1. Templates are being silently dropped — fix the category

**This is the urgent one. It is breaking the product today.**

Two of our three templates are categorised **MARKETING**. Meta caps how many
marketing messages one person receives and **withholds the excess without
telling anyone** — the API returns success with a valid message ID, and the
message simply never arrives.

Demonstrated on 12 September, same recipient, same sender, same minute:

| Template | Category | Delivered |
|---|---|---|
| `appointment_booking_confirmation` ×3 | MARKETING | **no** |
| `appointment_rating` ×2 | MARKETING | **no** |
| `booking_cancellation` ×1 | **UTILITY** | **yes** |

All six accepted by Meta. Only the UTILITY one arrived.

**Why this gets worse, not better:** the cap trips on repeat messages to the
same person in a short window — which is exactly a clinic's normal day. The
busier the clinic, the more confirmations vanish.

**The argument for UTILITY:** both describe a transaction the patient has
already entered into — an appointment they just booked, and a visit that just
happened. Neither promotes anything. `booking_cancellation` was approved as
UTILITY with comparable content, which is the precedent to cite.

---

# 2. New wording for all three templates

The three were written separately and read that way. One opens with the
patient's name, one opens with nothing at all, and the third is in heavy
Egyptian colloquial. **None names the clinic** — so the patient's first sight
is an unknown number with no stated source, which is what makes a confirmation
feel like spam.

Full text, parameter tables and rationale: **[proposed_templates.md](proposed_templates.md)**

The shape all three share:

```
*<clinic>*
<doctor>

مرحبًا <patient>، <what this message is about>

<details, if any>

حجزك أسهل، ووقتك أثمن
```

Three things to know before submitting:

**Submit as new templates, not edits.** An edit replaces the live template the
moment Meta approves it. Our code sends an exact parameter count, so between
approval and our deploy there is a window where **every message fails**
(`#132000`). New names remove that window entirely — the old templates keep
working until we switch one database field, and rolling back is the same field.

Suggested: `appointment_confirmed_v2`, `appointment_rating_v2`,
`appointment_cancelled_v2`.

**Still three templates, not four.** The rewritten cancellation covers a single
booking *and* a whole day, because it says *your appointment* rather than
*today's appointments*. That one wording change also unblocks notifying a
patient when the clinic cancels their booking — which today sends nothing at
all, because no honest template exists for it.

**Register the rating template as `ar`.** It is currently `en_US` while being
written entirely in Arabic. It works only because our code sends `en_US` to
match.

---

# 3. Delivery receipts — two values needed

The endpoint is built, deployed and tested. It is refusing every callback on
purpose until the app secret is set, because an unverifiable delivery receipt
is worth less than none.

Until this is live we **cannot tell a delivered message from a dropped one**.
Establishing that section 1 was even happening took most of a day for that
reason.

### 3a. Register the callback

Meta dashboard → **WhatsApp → Configuration → Webhook**

| Field | Value |
|---|---|
| Callback URL | `https://elayadah.com/webhooks/whatsapp` |
| Verify token | `da9314a7302b9e886336570420a7956126976c446f853edb` |
| Subscribe to | the **`messages`** field |

### 3b. Send us the app secret

Meta dashboard → **Settings → Basic → App Secret**

Meta signs every callback with it. Without it the endpoint cannot tell Meta
from a stranger, and a stranger could mark any message delivered — or failed.

Details: **[delivery-receipts.md](delivery-receipts.md)**

---

# 4. The sender shows as a phone number, not a name

Patients see **`+20 12 83176126`**, not *Elayadah*. For an unsolicited message
about a medical appointment, an unnamed number is the single biggest reason to
distrust it — and to report it, which damages the sender's quality rating for
every clinic on the platform.

What Meta currently holds for the number:

```
verified_name              Elayadah          ← the name is set
name_status                AVAILABLE_WITHOUT_REVIEW   ← not APPROVED
is_official_business_account  false
code_verification_status   VERIFIED
quality_rating             GREEN
```

So the name exists but is not in the approved state that makes it display. The
step that changes this is **Meta Business Verification** — submitting the
company's legal documents in Business Manager. Worth confirming the exact
requirement in the dashboard, since Meta changes these rules.

### 4b. The business profile is empty — and this one we can fix

Everything a patient sees when they tap the number is blank. Meta holds only:

```
vertical: HEALTH
```

No description, no address, no email, no website, **no profile picture**.

This needs no approval and no dashboard access — it is an API call we can make
from here. Say the word and it will carry the logo, a description, the website
and a contact address. It will not replace the name with *Elayadah*, but it
turns a blank unknown number into something that looks like a real business.

---

# Summary

| # | Action | Owner | Blocking |
|---|---|---|---|
| 1 | Re-categorise two templates to UTILITY | product | **messages are being dropped now** |
| 2 | Submit three rewritten templates | product | tone, trust, cancellation notices |
| 3a | Register the webhook callback | product | we cannot see delivery failures |
| 3b | Send the app secret | product | same |
| 4a | Meta Business Verification | product | sender shows as a bare number |
| 4b | Fill the business profile | **us** | ready when you are |

Items 1 and 2 can go in **one review cycle** — submit the new wording already
categorised UTILITY.

Nothing on our side is waiting to be built. The moment templates are approved,
switching to them is one field per row, and the cancellation notice follows.
