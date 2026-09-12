# Delivery receipts

Sending a WhatsApp message only ever proved that **Meta accepted it**. Whether
it reached the patient, was read, or was silently dropped arrives on a separate
callback — and until this existed, `delivered_at` was null on every message the
system had ever sent.

That gap is not academic. A marketing-category template that exceeds a
per-user frequency cap is accepted with a valid message id and then never
delivered, with no error anywhere. It looks identical to success.

## The endpoint

```
GET  https://elayadah.com/webhooks/whatsapp   one-time handshake
POST https://elayadah.com/webhooks/whatsapp   status callbacks
```

## What Meta needs from you

In the Meta app dashboard, under **WhatsApp → Configuration → Webhook**:

| Field | Value |
|---|---|
| Callback URL | `https://elayadah.com/webhooks/whatsapp` |
| Verify token | the value of `WHATSAPP_VERIFY_TOKEN` on the server |
| Subscribe to | the **`messages`** field |

## What the server needs from Meta

```
WHATSAPP_APP_SECRET=     # Meta app dashboard -> Settings -> Basic -> App secret
WHATSAPP_VERIFY_TOKEN=   # any long random string; must match the dashboard
```

**The app secret is not optional.** Meta signs every callback with it, and the
endpoint refuses anything it cannot verify. An open webhook would let a
stranger mark any message delivered — or failed — and the entire value of this
is that its answer can be believed. Until the secret is set, every callback is
rejected with a 403 and nothing is recorded.

## What gets recorded

| Meta says | We store |
|---|---|
| `delivered` | `status=delivered`, `delivered_at` |
| `read` | `status=read`, `read_at`, and `delivered_at` if it was still empty |
| `failed` | `status=failed`, `error` — Meta's own code and wording |

Two details worth knowing:

**Order is not promised.** Meta may send `read` before `delivered`, or send
only one of them. States are ranked, and a late callback never walks a message
backwards.

**Everything gets a 200 except a bad signature.** Meta retries anything else
for hours, and a payload we cannot use is not worth being retried about.

## Reading the result

```sql
SELECT template_key, status, error, sent_at, delivered_at, read_at
FROM outbound_messages ORDER BY id DESC LIMIT 20;
```

A row sitting at `sent` long after `sent_at` was never delivered. Before this
existed, that row was indistinguishable from one that arrived.
