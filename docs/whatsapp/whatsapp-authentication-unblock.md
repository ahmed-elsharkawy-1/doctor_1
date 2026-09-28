# Enabling WhatsApp verification codes

Creating an **AUTHENTICATION** template currently fails with *"This WhatsApp
business account does not have permission to create message template"*.

Submitting the same content as **UTILITY** does not work either — Meta reads the
content, recommends Authentication, and warns the template will be rejected.
Mislabelling it deliberately is a policy breach that puts the number at risk, so
that route is closed.

This page is what to do about it. **Nothing here blocks the product**: codes will
be sent by SMS in the meantime, and switching to WhatsApp later is one setting.

---

## Why it is blocked

Read from Meta's API on 28 September:

```
is_official_business_account   false        ← the business is not verified
messaging_limit_tier           TIER_250     ← the starting tier for unverified accounts
name_status                    AVAILABLE_WITHOUT_REVIEW
code_verification_status       EXPIRED      ← the number's own verification has lapsed
account_mode                   LIVE         ✓
quality_rating                 GREEN        ✓
platform_type                  CLOUD_API    ✓
```

The number and the integration are healthy. What is missing is **Meta Business
Verification** on the business portfolio. Authentication templates are withheld
from unverified businesses, and `TIER_250` is the tier such accounts start on.

---

## What to do, in order

### 1. Re-verify the phone number

**Meta → WhatsApp Manager → Account tools → Phone numbers**

`code_verification_status` currently reads `EXPIRED` and should read `VERIFIED`.
Do this first — a verification submitted while the number's own status has
lapsed is likely to be rejected.

### 2. Submit Business Verification

**Meta Business Manager → Business settings → Security Centre → Start
verification**

Meta asks for the business's legal details and then documents proving them:

| Needed | Notes |
|---|---|
| Legal business name | must match the documents exactly |
| Business address | must match the documents |
| Business phone number | Meta may call or send a code to it |
| Website | must be live and mention the business name |
| Official document | commercial registration, tax card, or similar |

**The single most common cause of rejection is a mismatch** — the name on the
commercial registration differing from the name entered in Business Manager,
even slightly. Check them character by character before submitting.

Review usually takes several days and can run to a few weeks. It can be resubmitted
if rejected, so an early attempt is not wasted.

### 3. Create the AUTHENTICATION template

Only possible once step 2 completes. Then:

| Field | Value |
|---|---|
| Category | **AUTHENTICATION** |
| Language | `ar` |
| Suggested name | `booking_verification_code` |
| Parameters | one: the code |
| Button | **Copy code** |
| Expiry warning | **10 minutes** |

Meta writes the body of an authentication template itself — there is no custom
wording to prepare.

---

## What we do meanwhile

Verification codes go out by **SMS**, through a provider that needs no approval
from Meta. Patients can book from the day that is wired up.

When the template is finally approved, moving codes back to WhatsApp is a single
configuration change on our side. Nothing has to be rebuilt.

---

## Please report back

- The date Business Verification was **submitted** (so we know the clock started)
- Its outcome, and the exact reason if rejected
- Whether the phone number now reads `VERIFIED`
